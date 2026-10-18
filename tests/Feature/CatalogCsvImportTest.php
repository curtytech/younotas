<?php

use App\Models\Client;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CatalogCsvImportService;
use App\Services\FocusMunicipioService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    foreach ([
        '0001_01_01_000000_create_users_table.php',
        '2026_05_19_000003_create_clients_table.php',
        '2026_05_19_000005_create_products_table.php',
        '2026_05_19_000006_create_stock_movements_table.php',
    ] as $file) {
        (require database_path('migrations/'.$file))->up();
    }
    Schema::table('clients', fn (Blueprint $table) => $table->string('ibge_code', 7)->nullable());
    $this->company = User::findOrFail(2);
    $this->clientsPath = tempnam(sys_get_temp_dir(), 'clients');
    $this->productsPath = tempnam(sys_get_temp_dir(), 'products');
    $this->reportPath = tempnam(sys_get_temp_dir(), 'report');
    $this->mock(FocusMunicipioService::class, function ($mock): void {
        $mock->shouldReceive('ibgeCodeFor')->andReturn('3302502');
    });

    $stream = fopen($this->clientsPath, 'w');
    fputcsv($stream, ['Nome', 'CPF', 'CNPJ', 'Email principal', 'Telefone principal', 'Endereco', 'Cidade', 'Estado', 'Status'], ';', '"', '');
    fputcsv($stream, ['Cliente valido', '529.982.247-25', '', 'cliente@example.test', '21999999999', 'Rua Teste', 'Mage', 'RJ', 'ativado'], ';', '"', '');
    fputcsv($stream, ['Cliente sem email', '111.444.777-35', '', '', '21999999999', 'Rua Teste', 'Mage', 'RJ', 'ativado'], ';', '"', '');
    fclose($stream);
    file_put_contents($this->productsPath, "Codigo;EAN;Nome do Produto;Valor de Venda;Qt. Estoque;Qt. Minima\nABC-1;;Cilindro; 60L;R$ 1.234,56;2,500;1\n");
});

afterEach(function (): void {
    foreach ([$this->clientsPath, $this->productsPath, $this->reportPath] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    Schema::dropIfExists('stock_movements');
    Schema::dropIfExists('products');
    Schema::dropIfExists('clients');
    Schema::dropIfExists('password_reset_tokens');
    Schema::dropIfExists('sessions');
    Schema::dropIfExists('users');
});

test('migration importa apenas registros validos sem alterar as regras', function (): void {
    config([
        'catalog_import.clients_path' => $this->clientsPath,
        'catalog_import.products_path' => $this->productsPath,
        'catalog_import.report_path' => $this->reportPath,
    ]);
    $service = app(CatalogCsvImportService::class);
    $service->import(2, $this->clientsPath, $this->productsPath, $this->reportPath);
    $client = Client::firstOrFail();
    $product = Product::firstOrFail();
    $report = json_decode(file_get_contents($this->reportPath), true, flags: JSON_THROW_ON_ERROR);

    expect(Client::count())->toBe(1)
        ->and($client->user_id)->toBe($this->company->id)
        ->and($client->document)->toBe('52998224725')
        ->and($client->ibge_code)->toBe('3302502')
        ->and($product->sku)->toBe('ABC-1')
        ->and($product->code)->toBe((string) $product->id)
        ->and($product->name)->toBe('Cilindro; 60L')
        ->and($product->sale_price)->toBe('1234.56')
        ->and($product->stock_quantity)->toBe('2.500')
        ->and(StockMovement::count())->toBe(1)
        ->and($report['clients'][1]['status'])->toBe('rejected')
        ->and($report['clients'][1]['fields'])->toContain('email');

    $product->update(['stock_quantity' => 1]);
    $service->import(2, $this->clientsPath, $this->productsPath, $this->reportPath);
    expect(Client::count())->toBe(1)->and(Product::count())->toBe(1)
        ->and(StockMovement::count())->toBe(1)
        ->and($product->fresh()->stock_quantity)->toBe('1.000');
});

test('arquivos ausentes interrompem importacao sem gravar dados', function (): void {
    unlink($this->productsPath);
    expect(fn () => app(CatalogCsvImportService::class)->import($this->company->name, $this->clientsPath, $this->productsPath, $this->reportPath))
        ->toThrow(RuntimeException::class);
    expect(Client::count())->toBe(0)->and(Product::count())->toBe(0);
});

test('contato ausente recebe email provisorio unico e impede ativacao', function (): void {
    $service = app(CatalogCsvImportService::class);
    $service->import(2, $this->clientsPath, $this->productsPath, $this->reportPath, true);
    $client = Client::where('name', 'Cliente sem email')->firstOrFail();
    expect($client->email)->toEndWith('@cadastro.invalid')
        ->and($client->is_active)->toBeFalse()
        ->and($client->hasProvisionalData())->toBeTrue();
    expect(fn () => $client->update(['is_active' => true]))
        ->toThrow(ValidationException::class);
    $service->import(2, $this->clientsPath, $this->productsPath, $this->reportPath, true);
    expect(Client::count())->toBe(2);
    $client->update(['email' => 'real@example.test', 'is_active' => true]);
    expect($client->is_active)->toBeTrue();
});
