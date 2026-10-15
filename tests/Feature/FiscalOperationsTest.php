<?php

use App\Filament\Resources\FiscalDocumentResource\Pages\ListFiscalDocuments;
use App\Models\FiscalDocument;
use App\Models\FocusNfeSetting;
use App\Models\User;
use App\Services\FiscalIssueDiagnosis;
use App\Services\FiscalReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function operationsOwner(): array
{
    $owner = User::factory()->create(['role' => 'enterprise']);
    $setting = FocusNfeSetting::factory()->create(['user_id' => $owner->id]);
    $setting->company->update(['fiscal_settings' => array_replace_recursive($setting->company->fiscal_settings, ['nfse' => ['natureza_operacao' => '1']])]);
    $connection = $setting->company->connections()->where('environment', 'homologacao')->firstOrFail();

    return [$owner, $setting->company->fresh(), $connection];
}

function operationsDocument(array $company, array $attributes = []): FiscalDocument
{
    [$owner, $companyModel, $connection] = $company;

    return FiscalDocument::factory()->create(array_replace([
        'user_id' => $owner->id, 'company_id' => $companyModel->id,
        'fiscal_connection_id' => $connection->id, 'environment' => 'homologacao',
        'configuration_snapshot' => ['nfe' => ['cfop_padrao' => '5102']],
    ], $attributes));
}

test('checklist aponta credencial e habilitação ausentes por ambiente', function (): void {
    [, $company] = operationsOwner();
    $checks = app(FiscalReadiness::class)->checkAll($company);
    $missing = fn (string $environment): array => collect($checks[$environment]['items'])->reject->ok->pluck('key')->all();

    expect($missing('homologacao'))->toBe([])
        ->and($checks['homologacao']['ready'])->toBeTrue()
        ->and($checks['producao']['ready'])->toBeFalse()
        ->and($missing('producao'))->toContain('credential', 'enabled')
        ->and(json_encode($checks))->not->toContain('synthetic-focus-homologation-token');
});

test('checklist aponta campos fiscais obrigatórios ausentes do emitente', function (): void {
    [, $company] = operationsOwner();
    $settings = $company->fiscal_settings;
    unset($settings['nfe']['emitente']['inscricao_estadual'], $settings['prestador']['inscricao_municipal']);
    $company->update(['fiscal_settings' => $settings]);

    $missing = collect(app(FiscalReadiness::class)->check($company->fresh(), 'homologacao')['items'])->reject->ok->pluck('key')->all();

    expect($missing)->toBe(['nfse.prestador.inscricao_municipal', 'nfe.nfe.emitente.inscricao_estadual']);
});

test('pendências reúnem erros, notas paradas e origem não confirmada sem incluir notas saudáveis', function (): void {
    $context = operationsOwner();
    $healthy = operationsDocument($context);
    $rejected = operationsDocument($context, ['status' => 'erro_autorizacao']);
    $stuck = operationsDocument($context, ['status' => 'processando', 'last_checked_at' => now()->subHour()]);
    $recent = operationsDocument($context, ['status' => 'processando', 'last_checked_at' => now()->subMinute()]);
    $legacy = operationsDocument($context, ['fiscal_connection_id' => null, 'environment' => null]);

    $ids = FiscalDocument::query()->needingAttention()->pluck('id')->all();

    expect($ids)->toContain($rejected->id, $stuck->id, $legacy->id)
        ->not->toContain($healthy->id, $recent->id);
});

test('diagnóstico explica rejeição indicando campo e próximo passo', function (): void {
    $document = operationsDocument(operationsOwner(), [
        'status' => 'erro_autorizacao',
        'last_error' => ['operation' => 'consulta', 'response' => [
            'status' => 'erro_autorizacao', 'codigo' => 'requisicao_invalida', 'mensagem' => 'Dados inválidos',
            'erros' => [['campo' => 'itens.0.codigo_ncm', 'mensagem' => 'NCM inválido']],
        ]],
    ]);

    $diagnosis = app(FiscalIssueDiagnosis::class)->explain($document);

    expect($diagnosis['category'])->toBe('rejection')
        ->and($diagnosis['summary'])->toBe('Dados inválidos')
        ->and($diagnosis['details'])->toBe(['itens.0.codigo_ncm: NCM inválido'])
        ->and($diagnosis['action'])->toContain('Corrija');
});

test('diagnóstico de falha ambígua orienta consultar antes de reenviar e de origem legada orienta reconciliar', function (): void {
    $context = operationsOwner();
    $ambiguous = operationsDocument($context, ['status' => 'erro_emissao', 'last_error' => ['operation' => 'emissao', 'http_status' => null, 'message' => 'Sem resposta']]);
    $legacy = operationsDocument($context, ['status' => 'erro_emissao', 'fiscal_connection_id' => null, 'environment' => null]);

    expect(app(FiscalIssueDiagnosis::class)->explain($ambiguous)['action'])->toContain('Consulte a referência')
        ->and(app(FiscalIssueDiagnosis::class)->explain($legacy)['category'])->toBe('reconciliation');
});

test('lista fiscal separa pendências e mostra o diagnóstico', function (): void {
    $context = operationsOwner();
    $healthy = operationsDocument($context);
    $rejected = operationsDocument($context, ['status' => 'erro_autorizacao', 'last_error' => ['operation' => 'consulta', 'response' => ['mensagem' => 'Rejeição X']]]);
    $this->actingAs($context[0]);

    Livewire::test(ListFiscalDocuments::class, ['activeTab' => 'attention'])
        ->assertCanSeeTableRecords([$rejected])
        ->assertCanNotSeeTableRecords([$healthy])
        ->assertTableActionVisible('diagnostico', $rejected)
        ->assertTableActionHidden('diagnostico', $healthy);
});

test('exportação mensal entrega índice e XMLs apenas da empresa, ambiente e mês pedidos', function (): void {
    Storage::fake('local');
    $context = operationsOwner();
    [$owner, $company] = $context;
    $month = now()->subMonth();
    Storage::disk('local')->put('xml/a.xml', '<nfe>a</nfe>');
    Storage::disk('local')->put('xml/b.xml', '<nfe>b</nfe>');
    $inside = operationsDocument($context, ['xml_path' => 'xml/a.xml', 'issued_at' => $month, 'access_key' => str_repeat('1', 44), 'source_label' => '=HYPERLINK("x")', 'focus_reference' => '=cmd']);
    operationsDocument($context, ['xml_path' => 'xml/b.xml', 'issued_at' => now()->subMonths(3)]);
    operationsDocument($context, ['xml_path' => 'xml/b.xml', 'issued_at' => $month, 'environment' => 'producao', 'fiscal_connection_id' => $company->connections()->where('environment', 'producao')->value('id')]);
    $this->actingAs($owner);

    $response = $this->get(route('fiscal-documents.export', ['company_id' => $company->id, 'environment' => 'homologacao', 'month' => $month->format('Ym')]))->assertOk();
    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());
    $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $i): string => $zip->getNameIndex($i))->sort()->values()->all();
    $csv = $zip->getFromName('indice.csv');

    expect($names)->toBe(['indice.csv', 'xml/NF-e-'.str_repeat('1', 44).'.xml'])
        ->and(substr_count($csv, "\n"))->toBe(2)
        ->and($csv)->toContain($inside->document_number)->not->toContain(';=cmd');
});

test('exportação exige acesso à empresa e parâmetros válidos', function (): void {
    [, $company] = operationsOwner();
    $stranger = User::factory()->create(['role' => 'enterprise']);
    $this->actingAs($stranger);

    $this->get(route('fiscal-documents.export', ['company_id' => $company->id, 'environment' => 'homologacao', 'month' => now()->format('Ym')]))->assertForbidden();
    $this->get(route('fiscal-documents.export', ['company_id' => $company->id, 'environment' => 'x', 'month' => '2026']))->assertSessionHasErrors(['environment', 'month']);
});
