<?php

namespace App\Services;

use App\Exceptions\FocusProtocolException;
use App\Exceptions\FocusRateLimitedException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class FocusApiClient
{
    private const API_HOSTS = [
        'homologacao.focusnfe.com.br' => 'homologacao',
        'api.focusnfe.com.br' => 'producao',
    ];

    public function __construct(
        protected RateLimiter $rateLimiter,
    ) {}

    public function get(array $config, string $path, array $query = []): Response
    {
        return $this->send($config, 'GET', $path, ['query' => $query]);
    }

    public function post(array $config, string $path, array $body = [], array $query = [], ?string $accept = null): Response
    {
        return $this->send($config, 'POST', $path, [
            'body' => $body,
            'query' => $query,
            'accept' => $accept,
        ]);
    }

    public function delete(array $config, string $path, array $body = []): Response
    {
        return $this->send($config, 'DELETE', $path, ['body' => $body]);
    }

    public function download(array $config, string $url): string
    {
        $config = $this->validatedConfiguration($config);

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $url = $config['base_url'].$url;
        } elseif (! str_contains($url, '://') && ! str_starts_with($url, '//')) {
            $url = $config['base_url'].'/'.$url;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $ipAddress = trim($host, '[]');

        if (! $this->isHttpsUrl($parts) || $host === 'localhost'
            || (filter_var($ipAddress, FILTER_VALIDATE_IP) && ! filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
            || (isset(self::API_HOSTS[$host]) && $host !== parse_url($config['base_url'], PHP_URL_HOST))) {
            throw new InvalidArgumentException('URL de download fiscal não permitida.');
        }

        $attempts = max(1, (int) config('services.focus_nfe.downloads.attempts', 2));

        for ($attempt = 1; ; $attempt++) {
            $this->enforceRateLimit($config, 'downloads');

            try {
                $request = Http::connectTimeout(max(1, (int) config('services.focus_nfe.downloads.connect_timeout', 5)))
                    ->timeout(max(1, (int) config('services.focus_nfe.downloads.timeout', 120)))
                    ->withoutRedirecting();

                // URLs externas assinadas já autorizam o arquivo. Nunca enviar o token Focus a elas.
                if ($host === parse_url($config['base_url'], PHP_URL_HOST)) {
                    $request->withBasicAuth($config['api_key'], '');
                }

                $response = $request->get($url);
            } catch (ConnectionException) {
                if ($attempt >= $attempts) {
                    throw new ConnectionException('Falha de conexão ao baixar o arquivo fiscal.');
                }

                $this->waitForRetry($this->backoffDelay($attempt));

                continue;
            }

            if ($response->successful()) {
                return $response->body();
            }

            if (! $this->shouldRetry($response, $attempt, $attempts, true, $config, 'downloads')) {
                throw new RuntimeException('Falha ao baixar o arquivo fiscal (HTTP '.$response->status().').');
            }
        }
    }

    protected function send(array $config, string $method, string $path, array $options = []): Response
    {
        $config = $this->validatedConfiguration($config);
        $this->validateApiPath($path);
        $attempts = max(1, (int) config('services.focus_nfe.retries.attempts', 1));
        $safeToRetry = $this->safeToRetry($method, $path, $options);

        for ($attempt = 1; ; $attempt++) {
            $this->enforceRateLimit($config, $this->operationFor($path));

            try {
                $response = $this->attempt($config, $method, $path, $options);
            } catch (ConnectionException) {
                if (! $safeToRetry || $attempt >= $attempts) {
                    // A exceção original pode conter URL assinada ou detalhes da requisição.
                    throw new ConnectionException('Falha de conexão com a Focus. Consulte a referência antes de repetir uma emissão.');
                }

                $this->waitForRetry($this->backoffDelay($attempt));

                continue;
            }

            if ($response->successful()) {
                if (($options['accept'] ?? 'application/json') === 'application/json') {
                    $this->validateJsonResponse($response);
                }

                return $response;
            }

            if (! $this->shouldRetry($response, $attempt, $attempts, $safeToRetry, $config, $this->operationFor($path))) {
                return $response;
            }
        }
    }

    protected function attempt(array $config, string $method, string $path, array $options): Response
    {
        $request = Http::baseUrl($config['base_url'])
            ->withBasicAuth($config['api_key'], '')
            ->connectTimeout(max(1, (int) config('services.focus_nfe.http.connect_timeout', 5)))
            ->timeout(max(1, (int) config('services.focus_nfe.http.timeout', 30)))
            ->withoutRedirecting();

        $request->accept((string) ($options['accept'] ?? 'application/json'));
        $path = $this->withQuery($path, $options['query'] ?? []);

        return match ($method) {
            'GET' => $request->get($path),
            'POST' => $request->asJson()->post($path, $options['body'] ?? []),
            'DELETE' => $request->asJson()->delete($path, $options['body'] ?? []),
            default => throw new InvalidArgumentException('Método HTTP não suportado.'),
        };
    }

    protected function validatedConfiguration(array $config): array
    {
        // Ausência de credencial da empresa nunca herda um token global.
        if (! is_string($config['api_key'] ?? null) || trim($config['api_key']) === '') {
            throw new RuntimeException('Configure o token Focus do ambiente da empresa na Configuração Fiscal.');
        }

        $parts = parse_url(rtrim((string) ($config['base_url'] ?? ''), '/'));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! $this->isHttpsUrl($parts) || ! isset(self::API_HOSTS[$host])
            || filled($parts['path'] ?? null) || isset($parts['query'])
            || (isset($config['environment']) && $config['environment'] !== self::API_HOSTS[$host])) {
            throw new InvalidArgumentException('O ambiente e a URL da conexão Focus são inválidos ou incompatíveis.');
        }

        $config['base_url'] = 'https://'.$host;

        return $config;
    }

    protected function isHttpsUrl(array|false $parts): bool
    {
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && filled($parts['host'] ?? null)
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['fragment'])
            && (! isset($parts['port']) || $parts['port'] === 443);
    }

    protected function validateApiPath(string $path): void
    {
        $parts = parse_url($path);

        if (! preg_match('#^/v2(?:/|$)#', $path) || preg_match('/[\\\\\x00-\x20]/', $path)
            || isset($parts['fragment']) || preg_match('#(?:^|/)\.\.?(?:/|$)#', rawurldecode((string) ($parts['path'] ?? '')))) {
            throw new InvalidArgumentException('Rota da API Focus inválida.');
        }
    }

    protected function validateJsonResponse(Response $response): void
    {
        try {
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FocusProtocolException($response->status());
        }

        if (! is_array($data)) {
            throw new FocusProtocolException($response->status());
        }
    }

    protected function safeToRetry(string $method, string $path, array $options): bool
    {
        if ($method === 'GET') {
            return true;
        }

        // Repetir emissão somente com a mesma referência e o mesmo payload nesta chamada.
        return $method === 'POST'
            && in_array($path, ['/v2/nfe', '/v2/nfse'], true)
            && filled($options['query']['ref'] ?? null);
    }

    protected function shouldRetry(Response $response, int $attempt, int $attempts, bool $safeToRetry, array $config, string $operation): bool
    {
        if (! in_array($response->status(), [408, 429], true) && $response->status() < 500) {
            return false;
        }

        $delay = $this->retryDelay($response, $attempt);
        $inlineLimit = max(0, (int) config('services.focus_nfe.retries.max_inline_wait_seconds', 5));

        if (($response->status() === 429 && (! $safeToRetry || $attempt >= $attempts)) || $delay > $inlineLimit) {
            $this->rateLimiter->hit($this->throttleKey($config, $operation).':provider-pause', max(1, $delay));
            throw new FocusRateLimitedException(max(1, $delay));
        }

        if (! $safeToRetry || $attempt >= $attempts) {
            return false;
        }

        $this->waitForRetry($delay);

        return true;
    }

    protected function withQuery(string $path, array $query): string
    {
        if ($query === []) {
            return $path;
        }

        return $path.(str_contains($path, '?') ? '&' : '?').http_build_query($query);
    }

    protected function enforceRateLimit(array $config, string $operation): void
    {
        $maxAttempts = max(1, (int) config('services.focus_nfe.rate_limit.max_attempts', 300));
        $decaySeconds = max(1, (int) config('services.focus_nfe.rate_limit.decay_seconds', 60));
        $key = $this->throttleKey($config, $operation);
        $pauseKey = $key.':provider-pause';

        if ($this->rateLimiter->tooManyAttempts($pauseKey, 1)) {
            throw new FocusRateLimitedException($this->rateLimiter->availableIn($pauseKey));
        }

        if ($this->rateLimiter->tooManyAttempts($key, $maxAttempts)) {
            throw new FocusRateLimitedException($this->rateLimiter->availableIn($key));
        }

        $this->rateLimiter->hit($key, $decaySeconds);
    }

    protected function throttleKey(array $config, string $operation): string
    {
        $host = (string) parse_url($config['base_url'], PHP_URL_HOST);
        $tokenHash = substr(hash('sha256', $config['api_key']), 0, 16);

        return 'focus:'.$host.':'.$tokenHash.':'.$operation;
    }

    protected function operationFor(string $path): string
    {
        $parts = explode('/', trim((string) parse_url($path, PHP_URL_PATH), '/'));

        return $parts[1] ?? 'default';
    }

    protected function retryDelay(Response $response, int $attempt): int
    {
        $retryAfter = trim((string) $response->header('Retry-After'));

        if ($retryAfter !== '') {
            if (ctype_digit($retryAfter)) {
                return (int) $retryAfter;
            }

            $timestamp = strtotime($retryAfter, now()->getTimestamp());

            if ($timestamp !== false) {
                return max(0, $timestamp - now()->getTimestamp());
            }
        }

        return $response->status() === 429 ? 5 * $attempt : $this->backoffDelay($attempt);
    }

    protected function backoffDelay(int $attempt): int
    {
        return min(2 ** min($attempt, 6), 60);
    }

    protected function waitForRetry(int $seconds): void
    {
        if ($seconds > max(0, (int) config('services.focus_nfe.retries.max_inline_wait_seconds', 5))) {
            throw new FocusRateLimitedException($seconds);
        }

        $this->sleep($seconds);
    }

    protected function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            usleep($seconds * 1_000_000);
        }
    }
}
