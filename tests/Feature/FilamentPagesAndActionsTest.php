<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\ClientResource\Pages\ListClients;
use App\Filament\Resources\FiscalDocumentResource\Pages\ListFiscalDocuments;
use App\Filament\Resources\FocusNfeSettingResource\Pages\CreateFocusNfeSetting;
use App\Filament\Resources\FocusNfeSettingResource\Pages\ListFocusNfeSettings;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\SaleResource\Pages\CreateSale;
use App\Filament\Resources\SaleResource\Pages\ListSales;
use App\Filament\Resources\ServiceOrderResource\Pages\CreateServiceOrder;
use App\Filament\Resources\ServiceOrderResource\Pages\ListServiceOrders;
use App\Filament\Resources\ServiceResource\Pages\CreateService;
use App\Filament\Resources\ServiceResource\Pages\ListServices;
use App\Filament\Resources\StockMovementResource\Pages\CreateStockMovement;
use App\Filament\Resources\StockMovementResource\Pages\ListStockMovements;
use App\Filament\Resources\TechnicianResource\Pages\CreateTechnician;
use App\Filament\Resources\TechnicianResource\Pages\ListTechnicians;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('painel e páginas principais carregam para uma empresa', function (): void {
    $enterprise = User::factory()->create(['role' => 'enterprise']);
    $this->actingAs($enterprise);

    foreach ([
        Dashboard::class,
        ListClients::class,
        ListProducts::class,
        ListServices::class,
        ListTechnicians::class,
        ListServiceOrders::class,
        ListSales::class,
        ListStockMovements::class,
        ListFocusNfeSettings::class,
        ListFiscalDocuments::class,
    ] as $page) {
        Livewire::test($page)->assertOk();
    }
});

test('listas de cadastros exibem o botão de criar para uma empresa', function (): void {
    $enterprise = User::factory()->create(['role' => 'enterprise']);
    $this->actingAs($enterprise);

    foreach ([
        ListClients::class,
        ListProducts::class,
        ListServices::class,
        ListTechnicians::class,
        ListServiceOrders::class,
        ListSales::class,
        ListStockMovements::class,
        ListFocusNfeSettings::class,
    ] as $page) {
        Livewire::test($page)
            ->assertOk()
            ->assertActionVisible('create');
    }
});

test('cadastro de empresas só aparece para administradores', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertOk()
        ->assertActionVisible('create');

    expect(UserResource::canViewAny())->toBeTrue();

    $enterprise = User::factory()->create(['role' => 'enterprise']);

    $this->actingAs($enterprise);

    expect(UserResource::canViewAny())->toBeFalse();
});

test('importações fiscais aparecem e respeitam o perfil da empresa', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin);

    Livewire::test(ListFiscalDocuments::class)
        ->assertOk()
        ->assertActionVisible('importar_focus_nfe')
        ->assertActionVisible('importar_focus_nfse');

    $enterprise = User::factory()->create(['role' => 'enterprise']);
    $this->actingAs($enterprise);

    Livewire::test(ListFiscalDocuments::class)
        ->assertOk()
        ->assertActionVisible('importar_focus_nfe')
        ->assertActionVisible('importar_focus_nfse');
});

test('formularios principais carregam seus campos essenciais', function (): void {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin);

    foreach ([
        [CreateClient::class, 'name'],
        [CreateProduct::class, 'name'],
        [CreateService::class, 'name'],
        [CreateTechnician::class, 'name'],
        [CreateServiceOrder::class, 'client_id'],
        [CreateSale::class, 'sale_date'],
        [CreateStockMovement::class, 'product_id'],
        [CreateFocusNfeSetting::class, 'settings.api_key'],
    ] as [$page, $field]) {
        Livewire::test($page)
            ->assertOk()
            ->assertFormFieldExists($field);
    }
});
