<?php

use App\Actions\ConsultSaleNfeAction;
use App\Actions\EmitSaleNfeAction;
use App\Actions\EmitServiceNfseAction;
use App\Jobs\ConsultSaleNfeJob;
use App\Jobs\ConsultServiceNfseJob;
use App\Jobs\EmitSaleNfeJob;
use App\Jobs\ProcessFocusWebhookJob;
use App\Jobs\ReconcilePendingFiscalDocumentsJob;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FocusNfeWebhookEvent;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\FiscalDocumentWorkflow;
use App\Services\FiscalEmissionPreparation;
use App\Services\FocusNfeConfigService;
use App\Services\FocusNfseImportService;
use App\Services\FocusWebhookProcessor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function fiscalWorkflowContext(): array
{
    $user = User::factory()->create(['role' => 'enterprise', 'cnpj' => '12345678000195']);
    $user->focusNfeSetting()->create(['settings' => [
        'environment' => 'homologacao', 'api_keys' => ['homologacao' => 'hml-private', 'producao' => 'prod-private'],
        'prestador' => ['cnpj' => '12345678000195', 'inscricao_municipal' => '1234', 'codigo_municipio' => '3550308'],
        'nfse' => ['natureza_operacao' => '1', 'optante_simples_nacional' => true],
        'nfe' => ['natureza_operacao' => 'VENDA', 'emitente' => [
            'nome' => 'Empresa LTDA', 'nome_fantasia' => 'Empresa', 'logradouro' => 'Rua A', 'numero' => '1',
            'bairro' => 'Centro', 'municipio' => 'São Paulo', 'uf' => 'SP', 'cep' => '01001000',
            'inscricao_estadual' => '123456789', 'regime_tributario' => 1,
        ]],
    ]]);
    $company = $user->fresh()->primaryCompany;
    $hml = $company->connections()->where('environment', 'homologacao')->firstOrFail();
    $production = $company->connections()->where('environment', 'producao')->firstOrFail();
    $client = Client::create([
        'user_id' => $user->id, 'company_id' => $company->id, 'name' => 'Cliente original',
        'email' => uniqid().'@client.test', 'phone' => '11999999999', 'document_type' => 'cpf', 'document' => '52998224725',
        'address' => 'Rua B', 'address_number' => '2', 'neighborhood' => 'Centro', 'city' => 'São Paulo',
        'state' => 'SP', 'zip_code' => '01001000', 'ibge_code' => '3550308', 'is_active' => true,
    ]);
    $product = Product::create([
        'user_id' => $user->id, 'company_id' => $company->id, 'name' => 'Produto', 'ncm_code' => '61091000',
        'cfop_code' => '5102', 'unit' => 'UN', 'sale_price' => 100, 'stock_quantity' => 10, 'is_active' => true,
    ]);
    $sale = Sale::create([
        'user_id' => $user->id, 'company_id' => $company->id, 'client_id' => $client->id, 'number' => 'S-'.uniqid(),
        'sale_date' => now(), 'status' => 'completed', 'payment_status' => 'paid', 'subtotal_amount' => 100, 'total_amount' => 100,
    ]);
    SaleItem::create([
        'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Produto', 'product_code' => 'P1',
        'unit' => 'UN', 'quantity' => 1, 'unit_price' => 100, 'total_amount' => 100,
    ]);
    $service = ServiceOrder::create([
        'user_id' => $user->id, 'company_id' => $company->id, 'client_id' => $client->id, 'number' => 'OS-'.uniqid(),
        'status' => 'completed', 'subtotal_amount' => 100, 'total_amount' => 100,
    ]);
    $service->items()->create([
        'service_name' => 'Serviço', 'unit' => 'UN', 'quantity' => 1, 'unit_price' => 100, 'total_amount' => 100,
        'lc116_code' => '0107', 'municipal_service_code' => '0107', 'iss_aliquot' => 5,
    ]);

    return compact('user', 'company', 'hml', 'production', 'client', 'sale', 'service');
}

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    config(['services.focus_nfe.webhook_secret' => 'fiscal-secret']);
    $this->context = fiscalWorkflowContext();
});

test('reserva ambiente antes da fila e não serializa o token no job', function (): void {
    extract($this->context);
    $this->actingAs($user);
    $job = new EmitSaleNfeJob($sale->id);
    $document = FiscalDocument::findOrFail($job->documentId);
    $company->update(['default_environment' => 'producao']);
    Http::fake(['https://homologacao.focusnfe.com.br/v2/nfe*' => Http::response(['status' => 'processando'], 202)]);

    $job->handle(app(EmitSaleNfeAction::class));

    expect($document->fresh()->environment)->toBe('homologacao')
        ->and($document->fiscal_connection_id)->toBe($hml->id)
        ->and(json_encode($document->configuration_snapshot))->not->toContain('hml-private')
        ->and(serialize($job))->not->toContain('hml-private')
        ->and($document->focus_reference)->toMatch('/^[a-zA-Z0-9]+$/');
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://homologacao.focusnfe.com.br/'));
});

test('homologação e produção coexistem e só produção fatura a ordem', function (): void {
    extract($this->context);
    Http::fake(['*' => Http::response(['status' => 'autorizado', 'numero' => '10'], 200)]);

    app(EmitServiceNfseAction::class)->execute($service);
    $homologation = FiscalDocument::where('environment', 'homologacao')->firstOrFail();
    expect($service->fresh()->status)->toBe('completed');
    $company->update(['default_environment' => 'producao']);
    app(EmitServiceNfseAction::class)->execute($service->fresh());
    $productionDocument = FiscalDocument::where('environment', 'producao')->firstOrFail();

    expect($homologation->fresh()->status)->toBe('autorizado')
        ->and($productionDocument->status)->toBe('autorizado')
        ->and($productionDocument->focus_reference)->not->toBe($homologation->focus_reference)
        ->and($service->fresh()->status)->toBe('billed');
});

test('consulta preserva ambiente original e aceita rotação da credencial da mesma conexão', function (): void {
    extract($this->context);
    $document = app(FiscalEmissionPreparation::class)->reserve($sale);
    $company->update(['default_environment' => 'producao']);
    $hml->update(['api_key' => 'rotated-hml']);
    Http::fake(['https://homologacao.focusnfe.com.br/v2/nfe/*' => Http::response(['status' => 'autorizado', 'numero' => '11'], 200)]);

    app(ConsultSaleNfeAction::class)->execute($document);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('rotated-hml:')));
    expect($document->fresh()->environment)->toBe('homologacao')->and($document->fresh()->status)->toBe('autorizado');
});

test('retry ambíguo mantém referência payload e conexão após troca do padrão', function (): void {
    extract($this->context);
    $retrying = false;
    Http::fake(function ($request) use (&$retrying) {
        return $request->method() === 'POST'
            ? ($retrying ? Http::response(['status' => 'processando'], 202) : Http::response(['message' => 'Indisponível'], 500))
            : Http::response(['message' => 'Não encontrada'], 404);
    });
    expect(fn () => app(EmitSaleNfeAction::class)->execute($sale))->toThrow(RuntimeException::class);
    $document = FiscalDocument::firstOrFail();
    $originalPayload = $document->metadata['focus_payload'];
    $reference = $document->focus_reference;
    $client->update(['name' => 'Nome corrigido depois do envio']);
    $company->update(['default_environment' => 'producao']);
    $retrying = true;
    $retry = new EmitSaleNfeJob($sale->id, $document->id);

    $retry->handle(app(EmitSaleNfeAction::class));

    expect($document->fresh()->focus_reference)->toBe($reference)
        ->and($document->fresh()->environment)->toBe('homologacao')
        ->and($document->fresh()->metadata['focus_payload'])->toBe($originalPayload);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'homologacao.focusnfe.com.br'));
});

test('webhook antecipado não é rebaixado pela resposta do envio', function (): void {
    extract($this->context);
    Http::fake(function ($request) {
        $reference = FiscalDocument::firstOrFail()->focus_reference;
        $processor = app(FocusWebhookProcessor::class);
        $event = $processor->capture('NF-e', ['ref' => $reference, 'status' => 'autorizado', 'numero' => '12', 'ambiente' => 'homologacao']);
        $processor->process('NF-e', $event->id);

        return Http::response(['status' => 'processando'], 202);
    });

    app(EmitSaleNfeAction::class)->execute($sale);

    expect(FiscalDocument::firstOrFail()->status)->toBe('autorizado')
        ->and(FiscalDocument::firstOrFail()->raw_response['status'])->toBe('autorizado')
        ->and($sale->fresh()->focus_nfe_status)->toBe('autorizado');
});

test('legado sem identidade não consulta o ambiente selecionado por conveniência', function (): void {
    extract($this->context);
    $sale->update(['focus_nfe_ref' => 'legacyref', 'focus_nfe_status' => 'processando']);
    Http::fake();

    expect(fn () => app(ConsultSaleNfeAction::class)->execute($sale))->toThrow(RuntimeException::class, 'ambiente confirmados');
    Http::assertNothingSent();
});

test('webhook responde após persistir e delega processamento sem guardar segredo da URL', function (): void {
    extract($this->context);
    $document = app(FiscalEmissionPreparation::class)->reserve($sale);
    $payload = ['ref' => $document->focus_reference, 'status' => 'autorizado', 'numero' => '13'];
    $this->postJson('/api/webhooks/focus-nfe?token=fiscal-secret', $payload)->assertStatus(202)->assertJsonPath('success', true);
    $this->postJson('/api/webhooks/focus-nfe?token=fiscal-secret', array_reverse($payload, true))->assertStatus(202);

    $event = FocusNfeWebhookEvent::firstOrFail();
    expect(FocusNfeWebhookEvent::count())->toBe(1)->and($event->processed_at)->toBeNull()
        ->and($event->payload)->not->toHaveKey('token')->and($document->fresh()->status)->toBe('enviando');
    Queue::assertPushed(ProcessFocusWebhookJob::class);
    app(FocusWebhookProcessor::class)->process('NF-e', $event->id);
    expect($document->fresh()->status)->toBe('autorizado');
});

test('webhook com referência ambígua aguarda e CNPJ divergente nunca altera a nota', function (): void {
    extract($this->context);
    $first = app(FiscalEmissionPreparation::class)->reserve($sale);
    $other = fiscalWorkflowContext();
    $second = FiscalDocument::create([
        'user_id' => $other['user']->id, 'company_id' => $other['company']->id, 'fiscal_connection_id' => $other['hml']->id,
        'environment' => 'homologacao', 'configuration_snapshot' => FiscalEmissionPreparation::snapshot(app(FocusNfeConfigService::class)->forConnection($other['hml'])),
        'document_type' => 'NF-e', 'focus_reference' => $first->focus_reference, 'status' => 'processando',
    ]);
    $processor = app(FocusWebhookProcessor::class);
    $event = $processor->capture('NF-e', ['ref' => $first->focus_reference, 'status' => 'autorizado']);
    expect($processor->process('NF-e', $event->id))->toBeNull()->and($event->fresh()->processed_at)->toBeNull();
    $event = $processor->capture('NF-e', ['ref' => $first->focus_reference, 'status' => 'autorizado', 'cnpj_emitente' => '04252011000110']);
    expect($processor->process('NF-e', $event->id))->toBeNull()
        ->and($first->fresh()->status)->toBe('enviando')->and($second->fresh()->status)->toBe('processando');
});

test('importação não vincula referência de outra empresa à sua ordem', function (): void {
    extract($this->context);
    $document = app(FiscalEmissionPreparation::class)->reserve($service);
    $other = fiscalWorkflowContext();
    Http::fake(['*' => Http::response(['status' => 'autorizado', 'numero' => '14', 'cnpj_prestador' => '12345678000195'], 200)]);

    app(FocusNfseImportService::class)->importByReference($other['user'], $document->focus_reference, $other['hml']->id);
    $imported = FiscalDocument::where('company_id', $other['company']->id)->firstOrFail();
    expect($imported->source_id)->toBeNull()->and($imported->source_type)->toBeNull()
        ->and($document->fresh()->status)->toBe('enviando');
});

test('reconciliação cobre ambos os documentos abandonados e respeita next_check_at', function (): void {
    extract($this->context);
    $nfe = app(FiscalEmissionPreparation::class)->reserve($sale);
    $nfse = app(FiscalEmissionPreparation::class)->reserve($service);
    $nfe->update(['last_sent_at' => now()->subMinutes(10), 'next_check_at' => now()->subMinute()]);
    $nfse->update(['status' => 'erro_emissao', 'next_check_at' => now()->subMinute()]);

    app(ReconcilePendingFiscalDocumentsJob::class)->handle();

    Queue::assertPushed(ConsultSaleNfeJob::class, fn ($job) => $job->documentId === $nfe->id);
    Queue::assertPushed(ConsultServiceNfseJob::class, fn ($job) => $job->documentId === $nfse->id);
    expect($nfe->fresh()->next_check_at->isFuture())->toBeTrue()->and($nfse->fresh()->next_check_at->isFuture())->toBeTrue();
});

test('revogação da participação impede emissão já enfileirada', function (): void {
    extract($this->context);
    $this->actingAs($user);
    $job = new EmitSaleNfeJob($sale->id);
    $company->members()->detach($user->id);
    Http::fake();

    expect(fn () => $job->handle(app(EmitSaleNfeAction::class)))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

test('reenvio definitivo corrige dados na conexão original sem substituir emissão de outro ambiente', function (): void {
    extract($this->context);
    $phase = 0;
    Http::fake(function ($request) use (&$phase) {
        if ($request->method() === 'GET') {
            return Http::response(['message' => 'Não encontrada'], 404);
        }

        return $phase === 0 ? Http::response(['message' => 'Configuração inválida'], 400) : Http::response(['status' => 'processando'], 202);
    });
    expect(fn () => app(EmitSaleNfeAction::class)->execute($sale))->toThrow(RuntimeException::class);
    $old = FiscalDocument::firstOrFail();
    $phase = 1;
    $company->update(['default_environment' => 'producao']);
    app(EmitSaleNfeAction::class)->execute($sale->fresh());
    $productionReference = $sale->fresh()->focus_nfe_ref;
    $client->update(['name' => 'Cliente corrigido']);
    $settings = $company->fresh()->fiscal_settings;
    $settings['nfe']['natureza_operacao'] = 'VENDA CORRIGIDA';
    $company->update(['fiscal_settings' => $settings]);
    $job = new EmitSaleNfeJob($sale->id, $old->id);

    $job->handle(app(EmitSaleNfeAction::class));

    expect($old->fresh()->metadata['focus_payload']['nome_destinatario'])->toBe('Cliente corrigido')
        ->and($old->fresh()->metadata['focus_payload']['natureza_operacao'])->toBe('VENDA CORRIGIDA')
        ->and($old->fresh()->environment)->toBe('homologacao')
        ->and($sale->fresh()->focus_nfe_ref)->toBe($productionReference)
        ->and($sale->fresh()->focus_nfe_environment)->toBe('producao');
});

test('validação local libera correção sem tratar dados inválidos como envio incerto', function (): void {
    extract($this->context);
    $client->update(['document' => '00000000000']);
    Http::fake(['*' => Http::response(['status' => 'processando'], 202)]);
    expect(fn () => app(EmitServiceNfseAction::class)->execute($service))->toThrow(RuntimeException::class);
    $document = FiscalDocument::firstOrFail();
    expect($document->status)->toBe('erro_emissao')->and($document->last_error['http_status'])->toBe(0)
        ->and($document->next_check_at)->toBeNull();
    Http::assertNothingSent();
    $client->update(['document' => '52998224725']);

    app(EmitServiceNfseAction::class)->execute($service->fresh());

    expect($document->fresh()->status)->toBe('processando');
});

test('falha de envio após webhook autorizado preserva o sucesso confirmado', function (): void {
    extract($this->context);
    Http::fake(function ($request) {
        $document = FiscalDocument::firstOrFail();
        $processor = app(FocusWebhookProcessor::class);
        $event = $processor->capture('NF-e', ['ref' => $document->focus_reference, 'status' => 'autorizado', 'numero' => '15']);
        $processor->process('NF-e', $event->id);
        throw new \Illuminate\Http\Client\ConnectionException('Timeout depois de aceitar');
    });

    $response = app(EmitSaleNfeAction::class)->execute($sale);

    expect($response['status'])->toBe('autorizado')->and(FiscalDocument::firstOrFail()->last_error)->toBeNull()
        ->and(FiscalDocument::firstOrFail()->next_check_at)->toBeNull();
});

test('reserva que não chegou ao worker é reenfileirada sem nova identidade', function (): void {
    extract($this->context);
    $document = app(FiscalEmissionPreparation::class)->reserve($sale, $user->id);
    $document->update(['next_check_at' => now()->subMinute()]);

    app(ReconcilePendingFiscalDocumentsJob::class)->handle();

    Queue::assertPushed(EmitSaleNfeJob::class, fn ($job) => $job->documentId === $document->id && $job->actorId === $user->id);
    Queue::assertNotPushed(ConsultSaleNfeJob::class);
    expect(FiscalDocument::count())->toBe(1)->and($document->fresh()->last_sent_at)->toBeNull();
});
