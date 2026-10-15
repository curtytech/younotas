<?php

use App\Exceptions\FocusRateLimitedException;
use App\Services\FocusApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

$focusConfig = [
    'api_key' => 'token-test',
    'api_password' => '',
    'base_url' => 'https://homologacao.focusnfe.com.br',
];

beforeEach(function (): void {
    Cache::flush();
});

test('lança exceção quando o rate limit por token/operação é excedido', function () use ($focusConfig): void {
    config([
        'services.focus_nfe.rate_limit.max_attempts' => 2,
        'services.focus_nfe.rate_limit.decay_seconds' => 60,
    ]);
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $client = app(FocusApiClient::class);

    $client->get($focusConfig, '/v2/nfe/ref-1');
    $client->get($focusConfig, '/v2/nfe/ref-2');

    expect(fn (): mixed => $client->get($focusConfig, '/v2/nfe/ref-3'))
        ->toThrow(FocusRateLimitedException::class);
});

test('respeita Retry-After em respostas 429 antes de completar a requisição', function () use ($focusConfig): void {
    config(['services.focus_nfe.retries.attempts' => 2]);

    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/nfe/ref-429' => Http::sequence()
            ->push(['mensagem' => 'Muitas requisições'], 429, ['Retry-After' => '1'])
            ->push(['status' => 'autorizado'], 200),
    ]);

    $response = app(FocusApiClient::class)->get($focusConfig, '/v2/nfe/ref-429');

    expect($response->status())->toBe(200)
        ->and($response->json('status'))->toBe('autorizado')
        ->and(Http::recorded())->toHaveCount(2);
});

test('aplica autenticação básica e aceita JSON por padrão', function () use ($focusConfig): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    app(FocusApiClient::class)->post($focusConfig, '/v2/nfe', ['valor_total' => 100], ['ref' => 'sale-1']);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/v2/nfe?ref=sale-1')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('token-test:'))
            && $request->hasHeader('Accept', 'application/json');
    });
});
