<?php

use App\Actions\ConsultSaleNfeAction;
use App\Actions\ConvertQuoteToSaleAction;
use App\Actions\EmitSaleNfeAction;
use App\Actions\PreviewSaleDanfeAction;
use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Service;
use App\Models\User;
use App\Services\FocusApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('auditoria opt-in: orçamento com produto e serviço vira venda, DANFE e NF-e reais em homologação', function () {
    $path = getenv('FOCUS_AUDIT_CONFIG');
    if (! $path) {
        $this->markTestSkipped('Defina FOCUS_AUDIT_CONFIG com arquivo privado para executar a auditoria real.');
    }
    \Illuminate\Support\Facades\Http::allowStrayRequests(['https://homologacao.focusnfe.com.br/*']);
    $rows = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $config = collect($rows)->first(fn ($row) => $row['company_id'] === 2 && $row['environment'] === 'homologacao')['config'];
    expect($config['base_url'])->toBe('https://homologacao.focusnfe.com.br');

    // Empresa local com o emitente e o token de homologação; o resto é sintético.
    $user = User::factory()->create(['role' => 'admin']);
    $company = Company::factory()->create(['cnpj' => data_get($config, 'prestador.cnpj'), 'default_environment' => 'homologacao',
        'fiscal_settings' => ['prestador' => $config['prestador'] ?? [], 'nfe' => ['emitente' => data_get($config, 'nfe.emitente', [])]]]);
    $company->connections()->create(['provider' => 'focus', 'environment' => 'homologacao', 'api_key' => $config['api_key'], 'enabled' => true]);
    $user->companies()->attach($company, ['role' => 'owner']);
    $user->update(['primary_company_id' => $company->id]);
    $this->actingAs($user);
    $client = Client::factory()->create([
        'user_id' => $user->id, 'company_id' => $company->id,
        'name' => 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL',
        'document_type' => 'cpf', 'document' => '52998224725',
        'address' => 'Rua de Teste', 'address_number' => '10', 'neighborhood' => 'Centro',
        'city' => 'Magé', 'state' => 'RJ', 'ibge_code' => '3302502', 'zip_code' => '25900000', 'country' => 'BR',
    ]);
    $product = Product::factory()->create([
        'user_id' => $user->id, 'company_id' => $company->id, 'name' => 'Produto de teste sem valor fiscal',
        'ncm_code' => '87089990', 'cfop_code' => '5102', 'stock_quantity' => 10, 'sale_price' => 25,
        'icms_aliquot' => 0, 'pis_aliquot' => 0, 'cofins_aliquot' => 0, 'ipi_aliquot' => 0,
    ]);
    $service = Service::factory()->create(['user_id' => $user->id, 'company_id' => $company->id, 'unit_price' => 80]);
    $quote = Quote::query()->create(['user_id' => $user->id, 'company_id' => $company->id, 'client_id' => $client->id,
        'number' => 'ORC-AUDIT', 'quote_date' => now(), 'status' => 'sent']);
    $quote->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'name' => $product->name, 'unit' => 'UN', 'quantity' => 2, 'unit_price' => 25]);
    $quote->items()->create(['item_type' => 'service', 'service_id' => $service->id, 'name' => $service->name, 'unit' => 'UN', 'quantity' => 1, 'unit_price' => 80]);

    // Mesma ação usada pelo botão "Gerar venda".
    $sale = app(ConvertQuoteToSaleAction::class)->execute($quote);
    expect($sale->saleItems)->toHaveCount(1)->and((float) $sale->total_amount)->toBe(50.0);

    $preview = app(PreviewSaleDanfeAction::class)->execute($sale);
    expect($preview->getStatusCode())->toBe(200)->and(substr($preview->getContent(), 0, 4))->toBe('%PDF');
    if (getenv('FOCUS_AUDIT_EMIT') !== '1') {
        return;
    }

    // Mesma ação usada pelo botão "Emitir NF-e"; depois consulta até sair do processamento.
    app(EmitSaleNfeAction::class)->execute($sale->fresh());
    for ($attempt = 0; $attempt < 10 && ! in_array($sale->fresh()->focus_nfe_status, ['autorizado', 'erro_autorizacao', 'denegado', 'cancelado'], true); $attempt++) {
        sleep(3);
        app(ConsultSaleNfeAction::class)->execute($sale->fresh());
    }
    $sale = $sale->fresh();
    $resultPath = dirname($path).'/focus-audit-result.json';
    file_put_contents($resultPath, json_encode(['reference' => $sale->focus_nfe_ref, 'environment' => 'homologacao',
        'status' => $sale->focus_nfe_status, 'error' => $sale->focus_nfe_error]));
    expect($sale->focus_nfe_status)->toBe('autorizado');

    $pdf = app(FocusApiClient::class)->download($config, $sale->focus_nfe_url);
    expect(substr($pdf, 0, 4))->toBe('%PDF');
})->group('live-focus');

test('auditoria opt-in baixa DANFE da referência de homologação preservada', function () {
    $path = getenv('FOCUS_AUDIT_CONFIG');
    if (! $path || ! is_file(dirname($path).'/focus-audit-result.json')) {
        $this->markTestSkipped('Execute a emissão opt-in em homologação antes deste teste.');
    }
    $rows = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $config = collect($rows)->first(fn ($row) => $row['company_id'] === 2 && $row['environment'] === 'homologacao')['config'];
    expect($config['base_url'])->toBe('https://homologacao.focusnfe.com.br');
    $result = json_decode(file_get_contents(dirname($path).'/focus-audit-result.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($result['environment'])->toBe('homologacao');
    Illuminate\Support\Facades\Http::allowStrayRequests(['https://homologacao.focusnfe.com.br/v2/nfe*']);
    $api = app(FocusApiClient::class);
    $response = $api->get($config, '/v2/nfe/'.$result['reference']);
    expect($response->json('status'))->toBe('autorizado');
    $url = $response->json('caminho_danfe') ?? $response->json('url_danfe');
    expect($url)->toBeString()->not->toBeEmpty();
    $absoluteUrl = str_starts_with($url, '/') ? $config['base_url'].$url : $url;
    Illuminate\Support\Facades\Http::allowStrayRequests([$absoluteUrl]);
    $pdf = $api->download($config, $url);
    expect(substr($pdf, 0, 4))->toBe('%PDF');
})->group('live-focus');
