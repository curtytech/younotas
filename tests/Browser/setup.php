<?php

use App\Models\Company;
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
file_put_contents($dir.'/danfe.pdf', Pdf::loadHTML('<h1>DANFE de teste sem valor fiscal</h1>')->output());
echo "Banco e usuário sintéticos preparados.\n";
