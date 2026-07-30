<?php

use App\Actions\CancelServiceNfseAction;
use App\Actions\ConsultServiceNfseAction;
use App\Actions\EmitServiceNfseAction;
use App\Actions\HandleFocusNfseWebhookAction;
use App\Jobs\ReconcileFocusNfseWebhooksJob;
use App\Models\Client;
use App\Models\FocusNfseWebhookEvent;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.focus_nfe.webhook_secret' => 'webhook-test-secret']);

    $this->user = User::create([
        'name' => 'Admin User',
        'email' => 'admin@younotas.local',
        'password' => bcrypt('password'),
        'role' => 'admin',
    ]);

    $this->user->focusNfeSetting()->create([
        'settings' => [
            'api_key' => 'token_test_focus_123',
            'base_url' => 'https://homologacao.focusnfe.com.br',
            'prestador' => [
                'cnpj' => '12345678000195',
                'inscricao_municipal' => '123456',
                'codigo_municipio' => '3550308',
            ],
            'nfse' => [
                'natureza_operacao' => '1',
                'optante_simples_nacional' => true,
                'incentivador_cultural' => false,
            ],
        ],
    ]);

    $this->client = Client::create([
        'user_id' => $this->user->id,
        'name' => 'Empresa Tomadora LTDA',
        'email' => 'tomador@empresa.com',
        'phone' => '11988887777',
        'document_type' => 'cnpj',
        'document' => '04252011000110',
        'address' => 'Rua das Flores',
        'address_number' => '123',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'ibge_code' => '3550308',
        'state' => 'SP',
        'zip_code' => '01234567',
        'country' => 'Brasil',
        'is_active' => true,
    ]);

    $this->service = ServiceOrder::create([
        'user_id' => $this->user->id,
        'client_id' => $this->client->id,
        'number' => 'OS-000001',
        'status' => 'completed',
        'total_amount' => 1500.00,
    ]);

    $this->service->items()->create([
        'service_name' => 'Serviço de Desenvolvimento de Software',
        'service_code' => 'SERV-001',
        'description' => 'Desenvolvimento web customizado',
        'unit' => 'UN',
        'quantity' => 1,
        'unit_price' => 1500.00,
        'total_amount' => 1500.00,
        'lc116_code' => '0107',
        'municipal_service_code' => '0107',
        'cnae_code' => '6201501',
        'iss_aliquot' => 5.00,
    ]);
});

test('pode emitir nfse via focus nfe', function (): void {
    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/nfse*' => Http::response([
            'status' => 'processando',
            'referencia' => 'ref-test-123',
        ], 201),
    ]);

    $action = app(EmitServiceNfseAction::class);
    $response = $action->execute($this->service);

    expect($response['status'])->toBe('processando');

    $this->service->refresh();
    expect($this->service->focus_nfse_status)->toBe('processando');
    expect($this->service->focus_nfse_ref)->not->toBeNull();
    expect($this->service->focus_nfse_response_secure['status'])->toBe('processando');
    expect($this->service->focus_nfse_response)->toBeNull();

    Http::assertSent(function ($request): bool {
        return Str::contains($request->url(), '/v2/nfse')
            && $request['servico']['valor_servicos'] == 1500.00
            && $request['servico']['item_lista_servico'] === '0107'
            && $request['tomador']['endereco']['codigo_municipio'] === '3550308'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('token_test_focus_123:'));
    });
});

test('pode consultar status de nfse emitida', function (): void {
    $this->service->update([
        'focus_nfse_ref' => 'ref-test-123',
        'focus_nfse_status' => 'processando',
    ]);

    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/nfse/ref-test-123*' => Http::response([
            'status' => 'autorizado',
            'numero' => '202600001',
            'url' => 'https://homologacao.focusnfe.com.br/danfse/202600001.pdf',
            'codigo_verificacao' => 'XYZ987',
        ], 200),
    ]);

    $action = app(ConsultServiceNfseAction::class);
    $response = $action->execute($this->service);

    expect($response['status'])->toBe('autorizado');

    $this->service->refresh();
    expect($this->service->focus_nfse_status)->toBe('autorizado');
    expect($this->service->focus_nfse_number)->toBe('202600001');
    expect($this->service->focus_nfse_url)->toBe('https://homologacao.focusnfe.com.br/danfse/202600001.pdf');
});

test('pode cancelar nfse autorizada', function (): void {
    $this->service->update([
        'focus_nfse_ref' => 'ref-test-123',
        'focus_nfse_status' => 'autorizado',
        'focus_nfse_number' => '202600001',
    ]);

    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/nfse/ref-test-123*' => Http::response([
            'status' => 'cancelado',
            'mensagem' => 'Cancelamento solicitado com sucesso',
        ], 200),
    ]);

    $action = app(CancelServiceNfseAction::class);
    $response = $action->execute($this->service, 'Cliente desistiu da compra do serviço prestado');

    expect($response['status'])->toBe('cancelado');

    $this->service->refresh();
    expect($this->service->focus_nfse_status)->toBe('cancelado');
});

test('pode receber webhook de atualizacao de nfse da focus nfe', function (): void {
    $this->service->update([
        'focus_nfse_ref' => 'ref-webhook-999',
        'focus_nfse_status' => 'processando',
    ]);

    $payload = [
        'ref' => 'ref-webhook-999',
        'status' => 'autorizado',
        'numero' => '202600099',
        'url' => 'https://homologacao.focusnfe.com.br/danfse/202600099.pdf',
    ];

    $response = $this->postJson('/api/webhooks/focus-nfse?token=webhook-test-secret', $payload);

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'status' => 'autorizado',
    ]);

    $this->service->refresh();
    expect($this->service->focus_nfse_status)->toBe('autorizado');
    expect($this->service->focus_nfse_number)->toBe('202600099');
    expect($this->service->focus_nfse_url)->toBe('https://homologacao.focusnfe.com.br/danfse/202600099.pdf');
});

test('normaliza o status processando_autorizacao recebido no webhook de nfse', function (): void {
    $this->service->update(['focus_nfse_ref' => 'ref-webhook-processing', 'focus_nfse_status' => 'enviando']);

    $this->postJson('/api/webhooks/focus-nfse?token=webhook-test-secret', [
        'ref' => 'ref-webhook-processing',
        'status' => 'processando_autorizacao',
        'numero_rps' => '10',
    ])->assertOk()->assertJson(['status' => 'processando']);

    expect($this->service->refresh()->focus_nfse_status)->toBe('processando')
        ->and($this->service->focus_nfse_last_webhook_at)->not->toBeNull();
});

test('usa o código IBGE do tomador, não o do prestador', function (): void {
    $this->client->update(['ibge_code' => '3304557']);

    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/nfse*' => Http::response(['status' => 'processando'], 201),
    ]);

    app(EmitServiceNfseAction::class)->execute($this->service);

    Http::assertSent(fn ($request): bool => $request['tomador']['endereco']['codigo_municipio'] === '3304557');
});

test('bloqueia reemissão quando a nota já está em processamento', function (): void {
    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/nfse*' => Http::response(['status' => 'processando'], 201),
    ]);

    $action = app(EmitServiceNfseAction::class);
    $action->execute($this->service);

    expect(fn () => $action->execute($this->service))
        ->toThrow(RuntimeException::class, 'não pode ser emitida');

    Http::assertSentCount(1);
});

test('persiste a mesma referência após falha de transporte para permitir reconciliação', function (): void {
    $attempt = 0;
    Http::fake(function ($request) use (&$attempt) {
        $attempt++;

        if ($request->method() === 'GET') {
            return Http::response(['mensagem' => 'Não encontrada'], 404);
        }

        return $attempt === 1
            ? Http::response(['mensagem' => 'Indisponível'], 500)
            : Http::response(['status' => 'processando'], 201);
    });

    $action = app(EmitServiceNfseAction::class);
    expect(fn () => $action->execute($this->service))->toThrow(RuntimeException::class);

    $this->service->refresh();
    $reference = $this->service->focus_nfse_ref;

    expect($reference)->not->toBeNull()
        ->and($this->service->focus_nfse_status)->toBe('erro_emissao')
        ->and($this->service->focus_nfse_payload['tomador']['cnpj'])->toBe('04252011000110')
        ->and($this->service->focus_nfse_error['operation'])->toBe('emissao');

    $action->execute($this->service);
    expect($this->service->refresh()->focus_nfse_ref)->toBe($reference);
});

test('reutiliza o payload original em retries da mesma referência', function (): void {
    $attempt = 0;
    Http::fake(function ($request) use (&$attempt) {
        $attempt++;

        if ($request->method() === 'GET') {
            return Http::response(['mensagem' => 'Não encontrada'], 404);
        }

        return $attempt === 1
            ? Http::response(['mensagem' => 'Indisponível'], 500)
            : Http::response(['status' => 'processando'], 201);
    });

    $action = app(EmitServiceNfseAction::class);
    expect(fn () => $action->execute($this->service))->toThrow(RuntimeException::class);

    $this->client->update(['name' => 'Cliente alterado depois da primeira tentativa']);
    $action->execute($this->service);

    $requests = collect(Http::recorded())->filter(fn (array $record): bool => $record[0]->method() === 'POST')->values();
    expect($requests)->toHaveCount(2)
        ->and($requests[0][0]['tomador']['razao_social'])->toBe($requests[1][0]['tomador']['razao_social'])
        ->and($requests[1][0]['tomador']['razao_social'])->toBe('Empresa Tomadora LTDA');
});

test('consulta uma referência após resposta ambígua e sincroniza nota autorizada', function (): void {
    Http::fake(function ($request) {
        return $request->method() === 'POST'
            ? Http::response(['mensagem' => 'Processamento indisponível'], 422)
            : Http::response(['status' => 'autorizado', 'numero' => '202600111'], 200);
    });

    app(EmitServiceNfseAction::class)->execute($this->service);

    expect($this->service->refresh()->focus_nfse_status)->toBe('autorizado')
        ->and($this->service->focus_nfse_number)->toBe('202600111');
});

test('rejeita webhook sem segredo e confirma recebimento de referência desconhecida para reconciliação', function (): void {
    $payload = ['ref' => 'nfse-desconhecida', 'status' => 'autorizado'];

    $this->postJson('/api/webhooks/focus-nfse', $payload)->assertUnauthorized();

    $this->postJson('/api/webhooks/focus-nfse?token=webhook-test-secret', $payload)
        ->assertStatus(202)
        ->assertJsonPath('success', true);

    expect(FocusNfseWebhookEvent::query()->where('reference', 'nfse-desconhecida')->count())->toBe(1);
});

test('reconcilia webhook recebido antes da persistência da referência', function (): void {
    $payload = ['ref' => 'ref-pendente-123', 'status' => 'autorizado', 'numero' => '202600222'];
    app(HandleFocusNfseWebhookAction::class)->execute($payload);

    $this->service->update(['focus_nfse_ref' => 'ref-pendente-123', 'focus_nfse_status' => 'processando']);

    app(ReconcileFocusNfseWebhooksJob::class, ['reference' => 'ref-pendente-123'])
        ->handle(app(HandleFocusNfseWebhookAction::class));

    expect($this->service->refresh()->focus_nfse_status)->toBe('autorizado')
        ->and($this->service->focus_nfse_number)->toBe('202600222');
});

test('recusa documento fiscal inválido fora da interface', function (): void {
    $this->client->update(['document' => '00000000000000']);

    expect(fn () => app(EmitServiceNfseAction::class)->execute($this->service))
        ->toThrow(RuntimeException::class, 'documento fiscal válido');
});

test('webhook atrasado não rebaixa nota autorizada para processando', function (): void {
    $this->service->update([
        'focus_nfse_ref' => 'ref-status-final',
        'focus_nfse_status' => 'autorizado',
    ]);

    $this->postJson('/api/webhooks/focus-nfse?token=webhook-test-secret', [
        'ref' => 'ref-status-final',
        'status' => 'processando',
    ])->assertOk();

    expect($this->service->refresh()->focus_nfse_status)->toBe('autorizado');
});
