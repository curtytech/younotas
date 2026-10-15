<?php

use App\Actions\CancelSaleNfeAction;
use App\Actions\ConsultSaleNfeAction;
use App\Actions\EmitSaleNfeAction;
use App\Jobs\ConsultSaleNfeJob;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FocusNfeWebhookEvent;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\FiscalEmissionPreparation;
use App\Services\FocusDanfePreviewService;
use App\Services\FocusNfeConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'name' => 'Empresa Teste', 'email' => 'nfe@test.local', 'password' => bcrypt('password'), 'role' => 'admin',
        'cnpj' => '12345678000195', 'razao_social' => 'Empresa Teste LTDA', 'inscricao_estatual' => '123456789',
        'address' => 'Rua A', 'address_number' => '10', 'city' => 'São Paulo', 'state' => 'SP', 'zip_code' => '01001000',
    ]);
    $this->user->focusNfeSetting()->create(['settings' => [
        'api_key' => 'token_test_focus_123', 'base_url' => 'https://homologacao.focusnfe.com.br',
        'prestador' => ['cnpj' => '12345678000195'],
        'nfe' => ['codigo_ncm_padrao' => '61091000', 'emitente' => [
            'nome' => 'Empresa Teste LTDA', 'nome_fantasia' => 'Empresa Teste', 'logradouro' => 'Rua A',
            'numero' => '10', 'bairro' => 'Centro', 'municipio' => 'São Paulo', 'uf' => 'SP',
            'cep' => '01001000', 'inscricao_estadual' => '123456789', 'regime_tributario' => 1,
        ]],
    ]]);
    $client = Client::create([
        'user_id' => $this->user->id, 'name' => 'Cliente Teste', 'email' => 'cliente@nfe.test', 'phone' => '11999999999',
        'document_type' => 'cpf', 'document' => '52998224725',
        'address' => 'Rua B', 'address_number' => '20', 'neighborhood' => 'Centro', 'city' => 'São Paulo',
        'state' => 'SP', 'zip_code' => '01002000', 'country' => 'Brasil', 'is_active' => true,
    ]);
    $product = Product::create([
        'user_id' => $this->user->id, 'name' => 'Produto Teste', 'ncm_code' => '61091000', 'cfop_code' => '5102',
        'unit' => 'UN', 'sale_price' => 100, 'stock_quantity' => 10, 'is_active' => true,
    ]);
    $sale = Sale::create([
        'user_id' => $this->user->id, 'client_id' => $client->id, 'number' => 'V-001', 'sale_date' => now(),
        'status' => 'completed', 'payment_status' => 'paid', 'issue_invoice' => true,
        'subtotal_amount' => 100, 'total_amount' => 100,
    ]);
    SaleItem::create([
        'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name, 'product_code' => (string) $product->id,
        'unit' => 'UN', 'quantity' => 1, 'unit_price' => 100, 'total_amount' => 100,
    ]);
    $config = app(FocusNfeConfigService::class)->forUser($this->user);
    $sale->forceFill([
        'focus_nfe_connection_id' => $config['fiscal_connection_id'],
        'focus_nfe_environment' => $config['environment'],
        'focus_nfe_configuration_snapshot' => FiscalEmissionPreparation::snapshot($config),
    ])->save();
    Queue::fake([ConsultSaleNfeJob::class]);
    $this->sale = $sale->fresh();
});

test('emite uma NF-e e agenda o acompanhamento assíncrono', function (): void {
    Http::fake(['https://homologacao.focusnfe.com.br/v2/nfe*' => Http::response(['status' => 'processando'], 202)]);

    $response = app(EmitSaleNfeAction::class)->execute($this->sale);

    expect($response['status'])->toBe('processando');
    $this->sale->refresh();
    expect($this->sale->focus_nfe_status)->toBe('processando')
        ->and($this->sale->focus_nfe_ref)->toMatch('/^sale\d+[a-z0-9]+$/')
        ->and($this->sale->focus_nfe_payload['items'][0]['codigo_ncm'])->toBe('61091000');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v2/nfe?ref='));
});

test('consulta e cancela uma NF-e autorizada', function (): void {
    $this->sale->update(['focus_nfe_ref' => 'sale1abc', 'focus_nfe_status' => 'processando']);
    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/nfe/sale1abc' => Http::sequence()
            ->push(['status' => 'autorizado', 'numero' => '100'], 200)
            ->push(['status' => 'cancelado'], 200),
    ]);

    app(ConsultSaleNfeAction::class)->execute($this->sale);
    $this->sale->refresh();
    expect($this->sale->focus_nfe_status)->toBe('autorizado');

    app(CancelSaleNfeAction::class)->execute($this->sale, 'Cancelamento por erro de emissão.');
    expect($this->sale->refresh()->focus_nfe_status)->toBe('cancelado');
});

test('processa webhook de NF-e de forma idempotente', function (): void {
    config(['services.focus_nfe.webhook_secret' => 'webhook-test-secret']);
    $this->sale->update(['focus_nfe_ref' => 'sale-webhook-001', 'focus_nfe_status' => 'processando']);

    $payload = [
        'ref' => 'sale-webhook-001',
        'status' => 'autorizado',
        'numero' => '123',
        'url_danfe' => 'https://example.test/danfe.pdf',
    ];

    $this->postJson('/api/webhooks/focus-nfe?token=webhook-test-secret', $payload)
        ->assertStatus(202)
        ->assertJson(['success' => true]);
    $this->postJson('/api/webhooks/focus-nfe?token=webhook-test-secret', $payload)
        ->assertStatus(202);

    expect($this->sale->refresh()->focus_nfe_status)->toBe('autorizado')
        ->and($this->sale->focus_nfe_number)->toBe('123')
        ->and($this->sale->focus_nfe_last_webhook_at)->not->toBeNull()
        ->and(FocusNfeWebhookEvent::where('reference', 'sale-webhook-001')->count())->toBe(1);
});

test('usa CFOP interestadual configurado para destinatário de outra UF', function (): void {
    $this->sale->client->update(['state' => 'RJ']);
    $this->sale->saleItems->first()->product->update(['cfop_interestadual' => '6102']);

    $payload = app(FocusDanfePreviewService::class)->buildPayload($this->sale->fresh());

    expect($payload['local_destino'])->toBe(2)
        ->and($payload['items'][0]['cfop'])->toBe('6102');
});

test('normaliza processando_autorizacao da Focus e agenda a consulta da NF-e', function (): void {
    Queue::fake();
    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/municipios*' => Http::response([], 200),
        'https://homologacao.focusnfe.com.br/v2/nfe*' => Http::response(['status' => 'processando_autorizacao'], 202),
    ]);

    app(EmitSaleNfeAction::class)->execute($this->sale);

    expect($this->sale->refresh()->focus_nfe_status)->toBe('processando');
    Queue::assertPushed(ConsultSaleNfeJob::class);
});

test('reenvia NF-e rejeitada na mesma referência com os dados atuais', function (): void {
    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/municipios*' => Http::response([], 200),
        'https://homologacao.focusnfe.com.br/v2/nfe*' => Http::response(['status' => 'processando_autorizacao'], 202),
    ]);
    $this->sale->update([
        'focus_nfe_ref' => 'sale1rejeitada', 'focus_nfe_status' => 'erro_autorizacao',
        'focus_nfe_payload' => ['nome_destinatario' => 'Nome antigo'],
    ]);
    $this->sale->client->update(['name' => 'Cliente Corrigido']);

    app(EmitSaleNfeAction::class)->execute($this->sale->fresh());

    $post = collect(Http::recorded())->first(fn (array $record): bool => $record[0]->method() === 'POST')[0];
    expect($this->sale->refresh()->focus_nfe_ref)->toBe('sale1rejeitada')
        ->and($post->url())->toContain('ref=sale1rejeitada')
        ->and($post['nome_destinatario'])->toBe('Cliente Corrigido');
});

test('remonta a NF-e após recusa definitiva da Focus em vez de repetir o payload antigo', function (): void {
    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/municipios*' => Http::response([], 200),
        'https://homologacao.focusnfe.com.br/v2/nfe*' => Http::response(['status' => 'processando_autorizacao'], 202),
    ]);
    $this->sale->update([
        'focus_nfe_ref' => 'sale1recusada', 'focus_nfe_status' => 'erro_emissao',
        'focus_nfe_payload' => ['nome_destinatario' => 'Nome antigo'],
        'focus_nfe_error' => ['operation' => 'emissao', 'http_status' => 400, 'message' => 'Empresa não habilitada'],
    ]);

    app(EmitSaleNfeAction::class)->execute($this->sale->fresh());

    $post = collect(Http::recorded())->first(fn (array $record): bool => $record[0]->method() === 'POST')[0];
    expect($post->url())->toContain('ref=sale1recusada')
        ->and($post['nome_destinatario'])->toBe('Cliente Teste');
});

test('emite nova NF-e com nova referência após cancelamento e preserva o histórico', function (): void {
    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/municipios*' => Http::response([], 200),
        'https://homologacao.focusnfe.com.br/v2/nfe*' => Http::response(['status' => 'processando_autorizacao'], 202),
    ]);
    $this->sale->update([
        'focus_nfe_ref' => 'sale1cancelada', 'focus_nfe_status' => 'cancelado', 'focus_nfe_number' => '10',
        'focus_nfe_url' => 'https://example.test/danfe-antiga.pdf',
    ]);

    app(EmitSaleNfeAction::class)->execute($this->sale->fresh());
    $this->sale->refresh();

    expect($this->sale->focus_nfe_ref)->not->toBe('sale1cancelada')
        ->and($this->sale->focus_nfe_number)->toBeNull()
        ->and($this->sale->focus_nfe_url)->toBeNull()
        ->and(FiscalDocument::where('focus_reference', 'sale1cancelada')->value('status'))->toBe('cancelado')
        ->and(FiscalDocument::where('focus_reference', $this->sale->focus_nfe_ref)->value('status'))->toBe('processando');
});

test('não reenvia NF-e autorizada', function (): void {
    Http::fake();
    $this->sale->update(['focus_nfe_ref' => 'sale1ok', 'focus_nfe_status' => 'autorizado']);

    expect(fn () => app(EmitSaleNfeAction::class)->execute($this->sale->fresh()))
        ->toThrow(RuntimeException::class, 'não pode ser emitida');
    Http::assertNothingSent();
});

test('consulta grava o link da DANFE retornado como caminho relativo', function (): void {
    $this->sale->update(['focus_nfe_ref' => 'sale1danfe', 'focus_nfe_status' => 'processando']);
    Http::fake(['https://homologacao.focusnfe.com.br/v2/nfe/sale1danfe' => Http::response([
        'status' => 'autorizado', 'numero' => '7', 'caminho_danfe' => '/arquivos/danfe.pdf',
    ], 200)]);

    app(ConsultSaleNfeAction::class)->execute($this->sale);

    expect($this->sale->refresh()->focus_nfe_url)->toBe('https://homologacao.focusnfe.com.br/arquivos/danfe.pdf');
});

test('exige CFOP explícito para cada destino sem conversão indiscriminada', function (): void {
    Http::fake(['https://homologacao.focusnfe.com.br/v2/municipios*' => Http::response([], 200)]);
    $this->sale->saleItems->first()->product->update(['cfop_code' => '5405']);
    $this->sale->client->update(['state' => 'RJ']);
    expect(fn () => app(FocusDanfePreviewService::class)->buildPayload($this->sale->fresh()))
        ->toThrow(RuntimeException::class, 'CFOP interestadual explícito');

    $this->sale->saleItems->first()->product->update(['cfop_interestadual' => '6404']);
    expect(app(FocusDanfePreviewService::class)->buildPayload($this->sale->fresh())['items'][0]['cfop'])->toBe('6404');

    $this->sale->saleItems->first()->product->update(['cfop_code' => '5102']);
    $this->sale->client->update(['state' => 'SP']);
    expect(app(FocusDanfePreviewService::class)->buildPayload($this->sale->fresh())['items'][0]['cfop'])->toBe('5102');
});

test('usa o horário atual de Brasília na emissão, mesmo para venda de outro dia', function (): void {
    Http::fake(['https://homologacao.focusnfe.com.br/v2/municipios*' => Http::response([], 200)]);
    $this->travelTo(now('America/Sao_Paulo')->setDate(2026, 10, 14)->setTime(22, 30));
    $this->sale->update(['sale_date' => '2026-10-01']);

    $payload = app(FocusDanfePreviewService::class)->buildPayload($this->sale->fresh());

    expect($payload['data_emissao'])->toBe('2026-10-14T22:30:00-03:00')
        ->and($payload['data_entrada_saida'])->toBe('2026-10-14T22:30:00-03:00');
});

test('usa PIS e COFINS 49 por padrão', function (): void {
    Http::fake(['https://homologacao.focusnfe.com.br/v2/municipios*' => Http::response([], 200)]);

    $item = app(FocusDanfePreviewService::class)->buildPayload($this->sale)['items'][0];

    expect($item['pis_situacao_tributaria'])->toBe('49')
        ->and($item['cofins_situacao_tributaria'])->toBe('49');
});

test('resolve e salva o código IBGE do destinatário pela tabela de municípios da Focus', function (): void {
    Http::fake(['https://homologacao.focusnfe.com.br/v2/municipios*' => Http::response([
        ['codigo_municipio' => '3550308', 'nome_municipio' => 'São Paulo', 'sigla_uf' => 'SP'],
    ], 200)]);

    $payload = app(FocusDanfePreviewService::class)->buildPayload($this->sale);

    expect($payload['codigo_municipio_destinatario'])->toBe('3550308')
        ->and($this->sale->client->refresh()->ibge_code)->toBe('3550308');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sigla_uf=SP')
        && str_contains(urldecode($request->url()), 'nome_municipio=São Paulo'));
});

test('histórico fiscal mostra número, série e chave da NF-e autorizada', function (): void {
    $this->sale->update(['focus_nfe_ref' => 'sale1serie', 'focus_nfe_status' => 'processando']);
    Http::fake(['https://homologacao.focusnfe.com.br/v2/nfe/sale1serie' => Http::response([
        'status' => 'autorizado', 'numero' => '9', 'serie' => '1',
        'chave_nfe' => 'NFe33261028480405000193550010000000091261896629',
    ], 200)]);

    app(ConsultSaleNfeAction::class)->execute($this->sale);

    $document = FiscalDocument::where('focus_reference', 'sale1serie')->firstOrFail();
    expect($document->document_number)->toBe('9')
        ->and($document->series)->toBe('1')
        ->and($document->access_key)->toBe('33261028480405000193550010000000091261896629');
});
