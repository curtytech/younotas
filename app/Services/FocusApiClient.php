<?php

namespace App\Services;

use App\Exceptions\FocusRateLimitedException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class FocusApiClient
{
    protected const MAX_RETRY_AFTER_SECONDS = 60;

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
        $baseUrl = rtrim((string) ($config['base_url'] ?? config('services.focus_nfe.base_url')), '/');

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = $baseUrl.'/'.ltrim($url, '/');
        }

        return Http::timeout(120)->retry(2, 2000)->get($url)->throw()->body();
    }

    protected function send(array $config, string $method, string $path, array $options = []): Response
    {
        $apiKey = (string) ($config['api_key'] ?? config('services.focus_nfe.api_key'));

        if (blank($apiKey)) {
            throw new RuntimeException('Configure a API Key da Focus na Configuração Fiscal.');
        }

        $this->enforceRateLimit($config, $this->operationFor($path));

        $attempts = max(1, (int) config('services.focus_nfe.retries.attempts', 3));
        $attempt = 0;

        while (true) {
            $attempt++;
            $response = $this->attempt($config, $method, $path, $options);

            if ($response->successful()) {
                return $response;
            }

            if ($attempt >= $attempts) {
                return $response;
            }

            $delay = $this->retryDelay($response, $attempt);

            if ($delay > 0) {
                $this->sleep($delay);
            }
        }
    }

    protected function attempt(array $config, string $method, string $path, array $options): Response
    {
        $baseUrl = rtrim((string) ($config['base_url'] ?? config('services.focus_nfe.base_url')), '/');
        $path = $this->withQuery($path, $options['query'] ?? []);

        $request = Http::baseUrl($baseUrl)
            ->withBasicAuth(
                (string) ($config['api_key'] ?? config('services.focus_nfe.api_key')),
                (string) ($config['api_password'] ?? config('services.focus_nfe.api_password', '')),
            )
            ->connectTimeout(5)
            ->timeout(30);

        if (($options['accept'] ?? null) !== null) {
            $request->accept((string) $options['accept']);
        } else {
            $request->acceptJson();
        }

        return match ($method) {
            'GET' => $request->get($path),
            'POST' => $request->asJson()->post($path, $options['body'] ?? []),
            'DELETE' => $request->asJson()->delete($path, $options['body'] ?? []),
            default => throw new InvalidArgumentException("Método HTTP não suportado: {$method}"),
        };
    }

    protected function withQuery(string $path, array $query): string
    {
        if ($query === []) {
            return $path;
        }

        $separator = str_contains($path, '?') ? '&' : '?';

        return $path.$separator.http_build_query($query);
    }

    protected function enforceRateLimit(array $config, string $operation): void
    {
        $maxAttempts = max(1, (int) config('services.focus_nfe.rate_limit.max_attempts', 300));
        $decaySeconds = max(1, (int) config('services.focus_nfe.rate_limit.decay_seconds', 60));

        $key = $this->throttleKey($config, $operation);

        if ($this->rateLimiter->tooManyAttempts($key, $maxAttempts)) {
            throw new FocusRateLimitedException($this->rateLimiter->availableIn($key));
        }

        $this->rateLimiter->hit($key, $decaySeconds);
    }

    protected function throttleKey(array $config, string $operation): string
    {
        $host = (string) parse_url((string) ($config['base_url'] ?? config('services.focus_nfe.base_url')), PHP_URL_HOST);
        $tokenHash = substr(hash('sha256', (string) ($config['api_key'] ?? '')), 0, 16);

        return 'focus:'.$host.':'.$tokenHash.':'.$operation;
    }

    protected function operationFor(string $path): string
    {
        $parts = explode('/', ltrim($path, '/'));

        return $parts[1] ?? $parts[0] ?? 'default';
    }

    protected function retryDelay(Response $response, int $attempt): int
    {
        if ($response->status() === 429) {
            $retryAfter = (int) $response->header('Retry-After', 0);

            return $retryAfter > 0
                ? min($retryAfter, self::MAX_RETRY_AFTER_SECONDS)
                : min(5 * $attempt, self::MAX_RETRY_AFTER_SECONDS);
        }

        if ($response->status() >= 500 || $response->status() === 408) {
            return min((2 ** $attempt), self::MAX_RETRY_AFTER_SECONDS);
        }

        return 0;
    }

    protected function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            usleep($seconds * 1_000_000);
        }
    }
}
