<?php

use App\Exceptions\FocusProtocolException;
use App\Exceptions\FocusRateLimitedException;
use App\Services\FocusApiClient;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function focusClientWithoutSleeping(): FocusApiClient
{
    return new class(app(RateLimiter::class)) extends FocusApiClient
    {
        public array $delays = [];

        protected function sleep(int $seconds): void
        {
            $this->delays[] = $seconds;
        }
    };
}

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    config([
        'services.focus_nfe.retries.attempts' => 1,
        'services.focus_nfe.retries.max_inline_wait_seconds' => 5,
        'services.focus_nfe.downloads.attempts' => 2,
        'services.focus_nfe.rate_limit.max_attempts' => 300,
        'services.focus_nfe.rate_limit.decay_seconds' => 60,
    ]);
    $this->focusConfig = [
        'api_key' => 'token-test',
        'base_url' => 'https://homologacao.focusnfe.com.br',
    ];
});

test('aceita respostas JSON 2xx de objetos e coleções', function (int $status, array $body): void {
    Http::fake(['*' => Http::response($body, $status)]);

    $response = app(FocusApiClient::class)->get($this->focusConfig, '/v2/backups');

    expect($response->status())->toBe($status)->and($response->json())->toBe($body);
})->with([[200, ['status' => 'autorizado']], [201, ['status' => 'processando']], [202, []], [200, [['mes' => '2026-01']]]]);

test('não repete erros permanentes mesmo com tentativas adicionais configuradas', function (int $status): void {
    config(['services.focus_nfe.retries.attempts' => 3]);
    Http::fake(['*' => Http::response(['mensagem' => 'Recusado'], $status)]);

    expect(focusClientWithoutSleeping()->get($this->focusConfig, '/v2/nfe/ref1')->status())->toBe($status);
    Http::assertSentCount(1);
})->with([400, 401, 403, 404, 422]);

test('repete apenas respostas transitórias seguras com backoff', function (int $status): void {
    config(['services.focus_nfe.retries.attempts' => 2]);
    Http::fake(['*' => Http::sequence()->push([], $status, ['Retry-After' => '1'])->push(['status' => 'autorizado'])]);
    $client = focusClientWithoutSleeping();

    expect($client->get($this->focusConfig, '/v2/nfe/ref1')->status())->toBe(200)
        ->and($client->delays)->toBe([1]);
    Http::assertSentCount(2);
})->with([408, 429, 500, 502, 503]);

test('devolve falha transitória final para classificação pelo serviço', function (int $status): void {
    config(['services.focus_nfe.retries.attempts' => 2]);
    Http::fake(['*' => Http::response([], $status)]);

    expect(focusClientWithoutSleeping()->get($this->focusConfig, '/v2/nfe/ref1')->status())->toBe($status);
    Http::assertSentCount(2);
})->with([408, 500]);

test('reagenda 429 esgotado com o prazo do provedor', function (): void {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '3'])]);

    try {
        focusClientWithoutSleeping()->get($this->focusConfig, '/v2/nfe/ref1');
        $this->fail('Era esperado reagendamento.');
    } catch (FocusRateLimitedException $exception) {
        expect($exception->retryAfterSeconds)->toBe(3);
    }
    Http::assertSentCount(1);
});

test('preserva Retry-After longo inteiro ou data sem bloquear o worker', function (bool $asDate, int $status): void {
    $this->freezeTime();
    config(['services.focus_nfe.retries.attempts' => 3]);
    $retryAfter = $asDate ? now()->addSeconds(180)->toRfc7231String() : '180';
    Http::fake(['*' => Http::response([], $status, ['Retry-After' => $retryAfter])]);
    $client = focusClientWithoutSleeping();

    try {
        $client->get($this->focusConfig, '/v2/nfe/ref1');
        $this->fail('Era esperado reagendamento.');
    } catch (FocusRateLimitedException $exception) {
        expect($exception->retryAfterSeconds)->toBe(180)->and($client->delays)->toBe([]);
    }
    Http::assertSentCount(1);
})->with([[false, 429], [true, 429], [false, 503], [true, 503]]);

test('contabiliza cada retry no limite local', function (): void {
    config(['services.focus_nfe.retries.attempts' => 2, 'services.focus_nfe.rate_limit.max_attempts' => 1]);
    Http::fake(['*' => Http::sequence()->push([], 500)->push(['status' => 'autorizado'])]);

    expect(fn () => focusClientWithoutSleeping()->get($this->focusConfig, '/v2/nfe/ref1'))
        ->toThrow(FocusRateLimitedException::class);
    Http::assertSentCount(1);
});

test('compartilha pausa do provedor entre documentos da mesma conexão e operação', function (): void {
    Http::fake(fn ($request) => str_contains($request->url(), '/v2/nfe/')
        ? Http::response([], 429, ['Retry-After' => '180'])
        : Http::response([], 200));
    $client = focusClientWithoutSleeping();

    expect(fn () => $client->get($this->focusConfig, '/v2/nfe/ref1'))->toThrow(FocusRateLimitedException::class);
    expect(fn () => $client->get($this->focusConfig, '/v2/nfe/ref2'))->toThrow(FocusRateLimitedException::class);
    expect($client->get($this->focusConfig, '/v2/nfse/ref2')->status())->toBe(200);
    Http::assertSentCount(2);
});

test('isola o rate limit por token ambiente e operação', function (): void {
    config(['services.focus_nfe.rate_limit.max_attempts' => 1]);
    Http::fake(['*' => Http::response([], 200)]);
    $client = app(FocusApiClient::class);
    $client->get($this->focusConfig, '/v2/nfe/ref1');
    $client->get(array_replace($this->focusConfig, ['api_key' => 'outro-token']), '/v2/nfe/ref1');
    $client->get(array_replace($this->focusConfig, ['base_url' => 'https://api.focusnfe.com.br']), '/v2/nfe/ref1');
    $client->get($this->focusConfig, '/v2/nfse/ref1');

    expect(fn () => $client->get($this->focusConfig, '/v2/nfe/ref2'))->toThrow(FocusRateLimitedException::class);
    Http::assertSentCount(4);
});

test('aplica Basic com senha vazia e aceita JSON', function (): void {
    Http::fake(['*' => Http::response([], 200)]);
    $config = array_replace($this->focusConfig, ['api_password' => 'não-deve-ser-enviada']);

    app(FocusApiClient::class)->post($config, '/v2/nfe', ['valor_total' => 100], ['ref' => 'sale1']);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v2/nfe?ref=sale1')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('token-test:'))
        && $request->hasHeader('Accept', 'application/json'));
});

test('não herda token global quando a conexão explícita não possui token', function (mixed $token): void {
    config(['services.focus_nfe.api_key' => 'global-secreto']);
    Http::fake();
    $config = array_replace($this->focusConfig, ['api_key' => $token]);

    expect(fn () => app(FocusApiClient::class)->get($config, '/v2/nfe/ref1'))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([null, '', '   ']);

test('rejeita URL base fora dos hosts HTTPS permitidos', function (string $url): void {
    Http::fake();

    expect(fn () => app(FocusApiClient::class)->get(array_replace($this->focusConfig, ['base_url' => $url]), '/v2/nfe/ref1'))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['http://api.focusnfe.com.br', 'https://api.focusnfe.com.br.evil.test', 'https://segredo@api.focusnfe.com.br', 'https://api.focusnfe.com.br/v2', 'https://api.focusnfe.com.br:444']);

test('impede incompatibilidade entre ambiente e host', function (): void {
    Http::fake();

    expect(fn () => app(FocusApiClient::class)->get(array_replace($this->focusConfig, ['environment' => 'producao']), '/v2/nfe/ref1'))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

test('impede que a rota substitua o host da API', function (string $path): void {
    Http::fake();

    expect(fn () => app(FocusApiClient::class)->get($this->focusConfig, $path))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['https://evil.test/v2/nfe', '//evil.test/v2/nfe', '/nfe/ref1', '/v2/nfe/ref 1', '/v2/../segredo', '/v2/%2e%2e/segredo', '/v2/nfe#fragmento']);

test('sanitiza respostas JSON inválidas sem repetir a emissão', function (string $body): void {
    config(['services.focus_nfe.retries.attempts' => 3]);
    Http::fake(['*' => Http::response($body, 200)]);

    try {
        app(FocusApiClient::class)->post($this->focusConfig, '/v2/nfe', ['sigilo' => 'payload'], ['ref' => 'sale1']);
        $this->fail('Era esperada resposta inválida.');
    } catch (FocusProtocolException $exception) {
        expect($exception->mayHaveBeenProcessed())->toBeTrue()
            ->and($exception->getMessage())->not->toContain($body, 'token-test', 'payload')
            ->and($exception->getPrevious())->toBeNull();
    }
    Http::assertSentCount(1);
})->with(['<xml>documento-sigiloso</xml>', 'null', '"conteudo"']);

test('aceita PDF sem tentar decodificar JSON', function (): void {
    Http::fake(['*' => Http::response('%PDF-preview', 200, ['Content-Type' => 'application/pdf'])]);

    expect(app(FocusApiClient::class)->post($this->focusConfig, '/v2/nfe/danfe', [], [], 'application/pdf')->body())
        ->toBe('%PDF-preview');
});

test('repete timeout seguro', function (): void {
    config(['services.focus_nfe.retries.attempts' => 2]);
    Http::fake(['*' => Http::sequence()->pushFailedConnection('url-assinada-secreta')->push(['status' => 'autorizado'])]);

    expect(focusClientWithoutSleeping()->get($this->focusConfig, '/v2/nfe/ref1')->status())->toBe(200);
    Http::assertSentCount(2);
});

test('sanitiza falha de transporte esgotada', function (): void {
    config(['services.focus_nfe.retries.attempts' => 2]);
    Http::fake(['*' => Http::failedConnection('token-test XML payload segredo')]);
    try {
        focusClientWithoutSleeping()->get($this->focusConfig, '/v2/nfe/ref1');
        $this->fail('Era esperado erro de conexão.');
    } catch (ConnectionException $exception) {
        expect($exception->getMessage())->not->toContain('token-test', 'XML', 'payload', 'segredo')
            ->and($exception->getPrevious())->toBeNull();
    }
});

test('reagenda backoff de transporte acima do limite local de espera', function (): void {
    config(['services.focus_nfe.retries.attempts' => 3, 'services.focus_nfe.retries.max_inline_wait_seconds' => 1]);
    Http::fake(['*' => Http::failedConnection()]);
    $client = focusClientWithoutSleeping();

    try {
        $client->get($this->focusConfig, '/v2/nfe/ref1');
        $this->fail('Era esperado reagendamento.');
    } catch (FocusRateLimitedException $exception) {
        expect($exception->retryAfterSeconds)->toBe(2)->and($client->delays)->toBe([]);
    }
    Http::assertSentCount(1);
});

test('repete emissão com referência preservando payload e referência', function (): void {
    config(['services.focus_nfe.retries.attempts' => 2]);
    Http::fake(['*' => Http::sequence()->pushFailedConnection()->push(['status' => 'processando'], 202)]);
    $payload = ['valor_total' => 100];

    focusClientWithoutSleeping()->post($this->focusConfig, '/v2/nfe', $payload, ['ref' => 'sale1']);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->data() === $payload && str_ends_with($request->url(), '?ref=sale1'));
});

test('não repete mutação sem idempotência conhecida', function (bool $transportFailure): void {
    config(['services.focus_nfe.retries.attempts' => 3]);
    Http::fake(['*' => $transportFailure ? Http::failedConnection() : Http::response([], 500)]);

    if ($transportFailure) {
        expect(fn () => focusClientWithoutSleeping()->post($this->focusConfig, '/v2/hooks'))->toThrow(ConnectionException::class);
    } else {
        expect(focusClientWithoutSleeping()->post($this->focusConfig, '/v2/hooks')->status())->toBe(500);
    }
    Http::assertSentCount(1);
})->with([false, true]);

test('downloads externos não recebem Basic e possuem limite por tentativa', function (): void {
    config(['services.focus_nfe.rate_limit.max_attempts' => 1]);
    Http::fake(['*' => Http::sequence()->push([], 500)->push('zip')]);

    expect(fn () => focusClientWithoutSleeping()->download($this->focusConfig, 'https://storage.example.test/arquivo.zip?signature=secreta'))
        ->toThrow(FocusRateLimitedException::class);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => ! $request->hasHeader('Authorization'));
});

test('downloads relativos autenticam apenas no host da conexão', function (): void {
    Http::fake(['*' => Http::response('zip')]);

    expect(app(FocusApiClient::class)->download($this->focusConfig, '/arquivos/mes.zip'))->toBe('zip');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://homologacao.focusnfe.com.br/arquivos/mes.zip'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('token-test:')));
});

test('downloads rejeitam HTTP credenciais e ambiente diferente', function (string $url): void {
    Http::fake();

    expect(fn () => app(FocusApiClient::class)->download($this->focusConfig, $url))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['http://storage.example.test/arquivo.zip', 'https://segredo@storage.example.test/arquivo.zip', 'https://api.focusnfe.com.br/arquivo.zip', 'https://127.0.0.1/arquivo.zip', 'https://[::1]/arquivo.zip']);

test('erro de download não expõe URL assinada nem corpo fiscal', function (): void {
    Http::fake(['*' => Http::response('XML token-test payload segredo', 422)]);

    try {
        app(FocusApiClient::class)->download($this->focusConfig, 'https://storage.example.test/arquivo.zip?signature=secreta');
        $this->fail('Era esperado erro de download.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain('secreta', 'XML', 'token-test', 'payload', 'segredo');
    }
    Http::assertSentCount(1);
});
