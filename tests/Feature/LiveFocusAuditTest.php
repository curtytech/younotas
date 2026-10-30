<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\FocusApiClient;
use App\Services\FocusDanfePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('auditoria opt-in gera DANFE real somente em homologação', function () {
    $path = getenv('FOCUS_AUDIT_CONFIG');
    if (! $path) {
        $this->markTestSkipped('Defina FOCUS_AUDIT_CONFIG com arquivo privado para executar a auditoria real.');
    }
    \Illuminate\Support\Facades\Http::allowStrayRequests(['https://homologacao.focusnfe.com.br/v2/nfe*']);
    $rows = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $row = collect($rows)->first(fn ($row) => $row['company_id'] === 2 && $row['environment'] === 'homologacao');
    $config = $row['config'];
    expect($config['base_url'])->toBe('https://homologacao.focusnfe.com.br');
    $user = User::factory()->create(['role' => 'admin']);
    $company = Company::factory()->create(['cnpj' => data_get($config, 'prestador.cnpj'), 'default_environment' => 'homologacao']);
    $config['company_id'] = $company->id;
    $client = Client::factory()->create([
        'user_id' => $user->id, 'company_id' => $company->id,
        'name' => 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL',
        'document_type' => 'cpf', 'document' => '52998224725',
        'address' => 'Rua de Teste', 'address_number' => '10', 'neighborhood' => 'Centro',
        'city' => 'Magé', 'state' => 'RJ', 'ibge_code' => '3302502', 'zip_code' => '25900000', 'country' => 'BR',
    ]);
    $product = Product::factory()->create([
        'user_id' => $user->id, 'company_id' => $company->id, 'name' => 'Produto de teste sem valor fiscal',
        'ncm_code' => '87089990', 'cfop_code' => '5102', 'stock_quantity' => 10,
        'icms_aliquot' => 0, 'pis_aliquot' => 0, 'cofins_aliquot' => 0, 'ipi_aliquot' => 0,
    ]);
    $sale = Sale::factory()->create(['user_id' => $user->id, 'company_id' => $company->id, 'client_id' => $client->id]);
    SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $product->id]);
    $payload = app(FocusDanfePreviewService::class)->buildPayload($sale->fresh(), $config);
    $response = app(FocusApiClient::class)->post($config, '/v2/nfe/danfe', $payload, [], 'application/pdf');
    expect($response->status())->toBe(200);
    expect(substr($response->body(), 0, 4))->toBe('%PDF');
    if (getenv('FOCUS_AUDIT_EMIT') !== '1') {
        return;
    }
    $reference = 'audit'.bin2hex(random_bytes(12));
    $resultPath = dirname($path).'/focus-audit-result.json';
    file_put_contents($resultPath, json_encode(['reference' => $reference, 'environment' => 'homologacao']));
    $api = app(FocusApiClient::class);
    $response = $api->post($config, '/v2/nfe', $payload, ['ref' => $reference]);
    file_put_contents($resultPath, json_encode([
        'reference' => $reference, 'environment' => 'homologacao', 'http' => $response->status(),
        'status' => $response->json('status'), 'code' => $response->json('codigo'),
        'errors' => $response->json('erros'),
    ]));
    expect($response->status())->toBeIn([201, 202]);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $response = $api->get($config, '/v2/nfe/'.$reference);
        $status = $response->json('status');
        if (! in_array($status, ['processando', 'processando_autorizacao'])) {
            break;
        }
        sleep(2);
    }
    file_put_contents($resultPath, json_encode([
        'reference' => $reference, 'environment' => 'homologacao', 'http' => $response->status(),
        'status' => $response->json('status'), 'sefaz_status' => $response->json('status_sefaz'),
        'sefaz_message' => $response->json('mensagem_sefaz'),
    ]));
    expect($response->json('status'))->toBe('autorizado');
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
