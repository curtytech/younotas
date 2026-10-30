<?php

use App\Services\CepService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('consulta o CEP e retorna os dados de endereco', function (): void {
    Http::fake([
        'https://viacep.com.br/ws/01001000/json/' => Http::response([
            'logradouro' => 'Praça da Sé',
            'bairro' => 'Sé',
            'localidade' => 'São Paulo',
            'uf' => 'SP',
            'ibge' => '3550308',
        ]),
    ]);

    expect(app(CepService::class)->addressFor('01001-000'))->toBe([
        'address' => 'Praça da Sé',
        'neighborhood' => 'Sé',
        'city' => 'São Paulo',
        'state' => 'SP',
        'ibge_code' => '3550308',
    ]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://viacep.com.br/ws/01001000/json/');
});

test('ignora CEP invalido ou inexistente', function (): void {
    Http::fake([
        'https://viacep.com.br/ws/99999999/json/' => Http::response(['erro' => true]),
    ]);

    $service = app(CepService::class);

    expect($service->addressFor('123'))->toBeNull()
        ->and($service->addressFor('99999-999'))->toBeNull();

    Http::assertSentCount(1);
});

test('nao interrompe o cadastro quando o servico de CEP falha', function (): void {
    Http::fake([
        'https://viacep.com.br/ws/01001000/json/' => fn () => throw new ConnectionException('timeout'),
    ]);

    expect(app(CepService::class)->addressFor('01001000'))->toBeNull();
});
