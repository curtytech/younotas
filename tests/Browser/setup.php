<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

$app = require __DIR__.'/bootstrap.php';
try {
    Http::get('https://api.focusnfe.com.br/v2/nfe/nao-enviar');
    throw new RuntimeException('O teste permitiu acesso externo.');
} catch (StrayRequestException) {
    // Confirma o bloqueio de produção antes de criar qualquer fixture.
}
$kernel = $app->make(Kernel::class);
$status = $kernel->call('migrate', ['--force' => true]);
if ($status !== 0) {
    throw new RuntimeException($kernel->output());
}
$user = User::query()->create(['name' => 'Administrador de teste', 'email' => 'admin@example.test', 'password' => 'senha-sintetica', 'role' => 'admin', 'is_active' => true]);
$company = Company::query()->create(['name' => 'Emitente E2E', 'cnpj' => '11222333000181', 'default_environment' => 'homologacao']);
$user->companies()->attach($company, ['role' => 'owner']);
$user->update(['primary_company_id' => $company->id]);
$company->update(['fiscal_settings' => [
    'prestador' => ['cnpj' => '11222333000181'],
    'nfe' => ['emitente' => ['nome' => 'Emitente E2E', 'logradouro' => 'Rua de Teste', 'numero' => '10', 'bairro' => 'Centro',
        'municipio' => 'Magé', 'uf' => 'RJ', 'cep' => '25900000', 'inscricao_estadual' => '123456789', 'regime_tributario' => 1]],
]]);
$company->connections()->create(['provider' => 'focus', 'environment' => 'homologacao', 'api_key' => 'browser-synthetic-token', 'enabled' => true]);
// Catálogo do fluxo de orçamento: produto pronto para NF-e e serviço que fica fora da venda.
Client::query()->create(['user_id' => $user->id, 'company_id' => $company->id, 'name' => 'Cliente Orçamento E2E', 'phone' => '21999999999', 'document_type' => 'cpf', 'document' => '39053344705',
    'address' => 'Rua de Teste', 'address_number' => '10', 'neighborhood' => 'Centro', 'city' => 'Magé', 'state' => 'RJ', 'ibge_code' => '3302502', 'zip_code' => '25900000', 'country' => 'BR']);
Product::query()->create(['user_id' => $user->id, 'company_id' => $company->id, 'name' => 'Produto Orçamento E2E', 'unit' => 'UN', 'sale_price' => 50,
    'stock_quantity' => 20, 'ncm_code' => '87089990', 'cfop_code' => '5102', 'is_active' => true]);
Service::query()->create(['user_id' => $user->id, 'company_id' => $company->id, 'name' => 'Serviço Orçamento E2E', 'unit' => 'UN', 'unit_price' => 120, 'is_active' => true]);
file_put_contents($dir.'/danfe.pdf', Pdf::loadHTML('<h1>DANFE de teste sem valor fiscal</h1>')->output());
echo "Banco e usuário sintéticos preparados.\n";
