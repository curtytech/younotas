<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\ClientResource\Pages\EditClient;
use App\Filament\Resources\ClientResource\Pages\ListClients;
use App\Filament\Resources\FiscalDocumentResource;
use App\Filament\Resources\FiscalDocumentResource\Pages\ListFiscalDocuments;
use App\Filament\Resources\FocusNfeSettingResource;
use App\Filament\Resources\FocusNfeSettingResource\Pages\CreateFocusNfeSetting;
use App\Filament\Resources\FocusNfeSettingResource\Pages\EditFocusNfeSetting;
use App\Filament\Resources\FocusNfeSettingResource\Pages\ListFocusNfeSettings;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\SaleResource;
use App\Filament\Resources\SaleResource\Pages\CreateSale;
use App\Filament\Resources\SaleResource\Pages\EditSale;
use App\Filament\Resources\SaleResource\Pages\ListSales;
use App\Filament\Resources\ServiceOrderResource;
use App\Filament\Resources\ServiceOrderResource\Pages\CreateServiceOrder;
use App\Filament\Resources\ServiceOrderResource\Pages\EditServiceOrder;
use App\Filament\Resources\ServiceOrderResource\Pages\ListServiceOrders;
use App\Filament\Resources\ServiceResource;
use App\Filament\Resources\ServiceResource\Pages\CreateService;
use App\Filament\Resources\ServiceResource\Pages\EditService;
use App\Filament\Resources\ServiceResource\Pages\ListServices;
use App\Filament\Resources\StockMovementResource;
use App\Filament\Resources\StockMovementResource\Pages\CreateStockMovement;
use App\Filament\Resources\StockMovementResource\Pages\EditStockMovement;
use App\Filament\Resources\StockMovementResource\Pages\ListStockMovements;
use App\Filament\Resources\TechnicianResource;
use App\Filament\Resources\TechnicianResource\Pages\CreateTechnician;
use App\Filament\Resources\TechnicianResource\Pages\EditTechnician;
use App\Filament\Resources\TechnicianResource\Pages\ListTechnicians;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Jobs\CancelSaleNfeJob;
use App\Jobs\CancelServiceNfseJob;
use App\Jobs\ConsultSaleNfeJob;
use App\Jobs\ConsultServiceNfseJob;
use App\Jobs\EmitSaleNfeJob;
use App\Jobs\EmitServiceNfseJob;
use App\Jobs\ImportFocusNfeBackupJob;
use App\Jobs\ImportFocusNfseByReferenceJob;
use App\Models\Client;
use App\Models\FocusNfeSetting;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\StockMovement;
use App\Models\Technician;
use App\Models\User;
use App\Support\NfeStatus;
use App\Support\NfseStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->enterprise = User::factory()->create([
        'name' => 'Empresa do Painel',
        'role' => 'enterprise',
    ]);
    $this->otherEnterprise = User::factory()->create([
        'name' => 'Outra Empresa',
        'role' => 'enterprise',
    ]);
    $this->admin = User::factory()->create([
        'name' => 'Administrador do Painel',
        'role' => 'admin',
    ]);
});

test('rotas das páginas do painel respondem 200 para os perfis autorizados', function (): void {
    $client = Client::factory()->create(['user_id' => $this->enterprise->id]);
    $product = Product::factory()->create(['user_id' => $this->enterprise->id]);
    $service = Service::factory()->create(['user_id' => $this->enterprise->id]);
    $technician = Technician::factory()->create(['user_id' => $this->enterprise->id]);
    $order = ServiceOrder::factory()->create([
        'user_id' => $this->enterprise->id,
        'client_id' => $client->id,
        'technician_id' => $technician->id,
    ]);
    $sale = Sale::factory()->create([
        'user_id' => $this->enterprise->id,
        'client_id' => $client->id,
    ]);
    $movement = StockMovement::factory()->create([
        'user_id' => $this->enterprise->id,
        'product_id' => $product->id,
    ]);

    $this->actingAs($this->enterprise);

    foreach ([
        Dashboard::getUrl(),
        ClientResource::getUrl('index'),
        ClientResource::getUrl('create'),
        ProductResource::getUrl('index'),
        ProductResource::getUrl('create'),
        ServiceResource::getUrl('index'),
        ServiceResource::getUrl('create'),
        TechnicianResource::getUrl('index'),
        TechnicianResource::getUrl('create'),
        ServiceOrderResource::getUrl('index'),
        ServiceOrderResource::getUrl('create'),
        SaleResource::getUrl('index'),
        SaleResource::getUrl('create'),
        StockMovementResource::getUrl('index'),
        StockMovementResource::getUrl('create'),
        FocusNfeSettingResource::getUrl('create'),
        FiscalDocumentResource::getUrl('index'),
        ClientResource::getUrl('edit', ['record' => $client]),
        ProductResource::getUrl('edit', ['record' => $product]),
        ServiceResource::getUrl('edit', ['record' => $service]),
        TechnicianResource::getUrl('edit', ['record' => $technician]),
        ServiceOrderResource::getUrl('edit', ['record' => $order]),
        SaleResource::getUrl('edit', ['record' => $sale]),
        StockMovementResource::getUrl('edit', ['record' => $movement]),
    ] as $url) {
        $response = $this->get($url);
        $this->assertSame(200, $response->status(), $url);
    }

    $this->get(FocusNfeSettingResource::getUrl('index'))
        ->assertRedirect(FocusNfeSettingResource::getUrl('create'));

    $setting = FocusNfeSetting::factory()->create(['user_id' => $this->enterprise->id]);

    $this->get(FocusNfeSettingResource::getUrl('edit', ['record' => $setting]))->assertOk();
    $this->get(FocusNfeSettingResource::getUrl('index'))
        ->assertRedirect(FocusNfeSettingResource::getUrl('edit', ['record' => $setting]));

    $this->actingAs($this->admin);

    foreach ([
        Dashboard::getUrl(),
        UserResource::getUrl('index'),
        UserResource::getUrl('create'),
        UserResource::getUrl('edit', ['record' => $this->enterprise]),
        UserResource::getUrl('view', ['record' => $this->enterprise]),
    ] as $url) {
        $this->get($url)->assertOk();
    }
});

test('componentes Livewire das páginas principais montam sem erro', function (): void {
    $client = Client::factory()->create(['user_id' => $this->enterprise->id]);
    $product = Product::factory()->create(['user_id' => $this->enterprise->id]);
    $service = Service::factory()->create(['user_id' => $this->enterprise->id]);
    $technician = Technician::factory()->create(['user_id' => $this->enterprise->id]);
    $order = ServiceOrder::factory()->create([
        'user_id' => $this->enterprise->id,
        'client_id' => $client->id,
        'technician_id' => $technician->id,
    ]);
    $sale = Sale::factory()->create([
        'user_id' => $this->enterprise->id,
        'client_id' => $client->id,
    ]);
    $movement = StockMovement::factory()->create([
        'user_id' => $this->enterprise->id,
        'product_id' => $product->id,
    ]);
    $setting = FocusNfeSetting::factory()->create(['user_id' => $this->enterprise->id]);

    $this->actingAs($this->enterprise);

    foreach ([
        Dashboard::class,
        ListClients::class,
        ListProducts::class,
        ListServices::class,
        ListTechnicians::class,
        ListServiceOrders::class,
        ListSales::class,
        ListStockMovements::class,
        ListFiscalDocuments::class,
        CreateClient::class,
        CreateProduct::class,
        CreateService::class,
        CreateTechnician::class,
        CreateServiceOrder::class,
        CreateSale::class,
        CreateStockMovement::class,
    ] as $page) {
        Livewire::test($page)->assertOk();
    }

    Livewire::test(ListFocusNfeSettings::class)
        ->assertRedirect(FocusNfeSettingResource::getUrl('edit', ['record' => $setting]));

    foreach ([
        [EditClient::class, $client],
        [EditProduct::class, $product],
        [EditService::class, $service],
        [EditTechnician::class, $technician],
        [EditServiceOrder::class, $order],
        [EditSale::class, $sale],
        [EditStockMovement::class, $movement],
        [EditFocusNfeSetting::class, $setting],
    ] as [$page, $record]) {
        Livewire::test($page, ['record' => $record->getRouteKey()])->assertOk();
    }

    $this->actingAs($this->admin);

    foreach ([ListUsers::class, CreateUser::class, EditUser::class, ViewUser::class] as $page) {
        $arguments = in_array($page, [EditUser::class, ViewUser::class], true)
            ? ['record' => $this->enterprise->getRouteKey()]
            : [];

        Livewire::test($page, $arguments)->assertOk();
    }
});

test('todos os formulários expõem os campos principais e os componentes repetíveis', function (): void {
    $this->actingAs($this->enterprise);

    $forms = [
        CreateClient::class => [
            'user_id', 'name', 'email', 'phone', 'document_type', 'inscricao_estatual',
            'document', 'address', 'address_number', 'address_complement', 'neighborhood',
            'city', 'ibge_code', 'state', 'zip_code', 'country', 'is_active',
        ],
        CreateProduct::class => [
            'user_id', 'sku', 'barcode', 'name', 'description', 'ncm_code', 'cest_code',
            'gtin', 'unit', 'cost_price', 'sale_price', 'stock_quantity', 'minimum_stock',
            'icms_aliquot', 'ipi_aliquot', 'pis_aliquot', 'cofins_aliquot', 'is_active', 'notes',
        ],
        CreateService::class => [
            'user_id', 'code', 'name', 'unit', 'unit_price', 'municipal_service_code',
            'lc116_code', 'cnae_code', 'nbs_code', 'iss_aliquot', 'pis_aliquot',
            'cofins_aliquot', 'inss_aliquot', 'ir_aliquot', 'csll_aliquot', 'description',
            'notes', 'is_active',
        ],
        CreateTechnician::class => ['user_id', 'name', 'phone', 'email', 'notes', 'is_active'],
        CreateServiceOrder::class => [
            'user_id', 'client_id', 'technician_id', 'status', 'scheduled_for',
            'problem_description', 'execution_description', 'signature_path', 'signed_by_name', 'notes',
        ],
        CreateSale::class => [
            'user_id', 'client_id', 'sale_date', 'status', 'payment_status', 'issue_invoice',
            'subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount', 'notes',
        ],
        CreateStockMovement::class => [
            'user_id', 'product_id', 'movement_type', 'source_type', 'reference', 'quantity',
            'previous_stock', 'current_stock', 'unit_cost', 'moved_at', 'notes',
        ],
        CreateFocusNfeSetting::class => [
            'user_id', 'settings.api_key', 'settings.base_url', 'settings.prestador.cnpj',
            'settings.prestador.inscricao_municipal', 'settings.prestador.codigo_municipio',
            'settings.nfse.natureza_operacao', 'settings.nfse.incentivador_cultural',
            'settings.nfse.optante_simples_nacional', 'settings.nfe.emitente.nome',
            'settings.nfe.emitente.nome_fantasia', 'settings.nfe.emitente.logradouro',
            'settings.nfe.emitente.numero', 'settings.nfe.emitente.bairro',
            'settings.nfe.emitente.municipio', 'settings.nfe.emitente.uf',
            'settings.nfe.emitente.cep', 'settings.nfe.emitente.inscricao_estadual',
            'settings.nfe.emitente.regime_tributario', 'settings.nfe.natureza_operacao',
            'settings.nfe.tipo_documento', 'settings.nfe.local_destino',
            'settings.nfe.finalidade_emissao', 'settings.nfe.consumidor_final',
            'settings.nfe.presenca_comprador', 'settings.nfe.modalidade_frete',
            'settings.nfe.forma_pagamento', 'settings.nfe.cfop_padrao',
            'settings.nfe.codigo_ncm_padrao', 'settings.nfe.icms_origem',
            'settings.nfe.icms_situacao_tributaria', 'settings.nfe.pis_situacao_tributaria',
            'settings.nfe.cofins_situacao_tributaria', 'settings.nfe.valor_frete',
            'settings.nfe.valor_seguro', 'settings.nfe.valor_outras_despesas',
        ],
    ];

    foreach ($forms as $page => $fields) {
        $livewire = Livewire::test($page)->assertOk();

        foreach ($fields as $field) {
            $livewire->assertFormFieldExists($field);
        }
    }

    $orderForm = Livewire::test(CreateServiceOrder::class)
        ->assertFormFieldExists('items');
    $orderFieldKeys = array_keys($orderForm->instance()->form->getFlatFields(withHidden: true));

    foreach ([
        'service_id', 'service_name', 'service_code', 'description', 'municipal_service_code',
        'lc116_code', 'cnae_code', 'nbs_code', 'unit', 'quantity', 'unit_price', 'total_amount',
        'iss_aliquot', 'pis_aliquot', 'cofins_aliquot', 'inss_aliquot', 'ir_aliquot', 'csll_aliquot',
    ] as $field) {
        expect(collect($orderFieldKeys)->contains(fn (string $key): bool => str_ends_with($key, '.'.$field)))->toBeTrue();
    }

    $saleForm = Livewire::test(CreateSale::class)
        ->assertFormFieldExists('saleItems');
    $saleFieldKeys = array_keys($saleForm->instance()->form->getFlatFields(withHidden: true));

    foreach ([
        'product_id', 'product_name', 'product_code', 'unit', 'quantity', 'unit_price',
        'discount_amount', 'tax_amount', 'total_amount',
    ] as $field) {
        expect(collect($saleFieldKeys)->contains(fn (string $key): bool => str_ends_with($key, '.'.$field)))->toBeTrue();
    }

    $this->actingAs($this->admin);

    $userForm = Livewire::test(CreateUser::class)->assertOk();

    foreach (['name', 'email', 'password', 'role'] as $field) {
        $userForm->assertFormFieldExists($field);
    }
});

test('selects carregam somente opções válidas para a empresa atual', function (): void {
    $ownClient = Client::factory()->create([
        'user_id' => $this->enterprise->id,
        'name' => 'Cliente da Empresa Atual',
    ]);
    $otherClient = Client::factory()->create([
        'user_id' => $this->otherEnterprise->id,
        'name' => 'Cliente de Outra Empresa',
    ]);
    $ownProduct = Product::factory()->create([
        'user_id' => $this->enterprise->id,
        'name' => 'Produto da Empresa Atual',
    ]);
    $otherProduct = Product::factory()->create([
        'user_id' => $this->otherEnterprise->id,
        'name' => 'Produto de Outra Empresa',
    ]);
    $ownService = Service::factory()->create([
        'user_id' => $this->enterprise->id,
        'name' => 'Serviço da Empresa Atual',
    ]);
    $otherService = Service::factory()->create([
        'user_id' => $this->otherEnterprise->id,
        'name' => 'Serviço de Outra Empresa',
    ]);
    $ownTechnician = Technician::factory()->create([
        'user_id' => $this->enterprise->id,
        'name' => 'Técnico Ativo da Empresa Atual',
        'is_active' => true,
    ]);
    $inactiveTechnician = Technician::factory()->create([
        'user_id' => $this->enterprise->id,
        'name' => 'Técnico Inativo',
        'is_active' => false,
    ]);
    $otherTechnician = Technician::factory()->create([
        'user_id' => $this->otherEnterprise->id,
        'name' => 'Técnico de Outra Empresa',
        'is_active' => true,
    ]);

    $hasOption = static fn (array $options, int $id): bool => array_key_exists($id, $options) || array_key_exists((string) $id, $options);

    $this->actingAs($this->enterprise);

    $salePage = Livewire::test(CreateSale::class);
    $saleFields = $salePage->instance()->form->getFlatFields(withHidden: true);
    $clientOptions = $saleFields['client_id']->getOptions();

    expect($hasOption($clientOptions, $ownClient->id))->toBeTrue()
        ->and($hasOption($clientOptions, $otherClient->id))->toBeFalse();

    $clientFields = Livewire::test(CreateClient::class)->instance()->form->getFlatFields(withHidden: true);
    expect($clientFields['document_type']->getOptions())->toHaveKeys(['cpf', 'cnpj', 'nif']);

    $stockPage = Livewire::test(CreateStockMovement::class);
    $stockFields = $stockPage->instance()->form->getFlatFields(withHidden: true);
    $productOptions = $stockFields['product_id']->getOptions();

    expect($hasOption($productOptions, $ownProduct->id))->toBeTrue()
        ->and($hasOption($productOptions, $otherProduct->id))->toBeFalse();

    expect($stockFields['movement_type']->getOptions())->toHaveKeys(['entry', 'exit', 'adjustment'])
        ->and($stockFields['source_type']->getOptions())->toHaveKeys(['manual', 'sale', 'return', 'inventory_adjustment']);

    $saleOptions = $saleFields['status']->getOptions();
    $paymentOptions = $saleFields['payment_status']->getOptions();

    expect($saleOptions)->toHaveKeys(['draft', 'pending', 'completed', 'canceled'])
        ->and($paymentOptions)->toHaveKeys(['pending', 'partial', 'paid', 'refunded', 'canceled']);

    $orderPage = Livewire::test(CreateServiceOrder::class);
    $orderFields = $orderPage->instance()->form->getFlatFields(withHidden: true);
    $orderClientOptions = $orderFields['client_id']->getOptions();
    $technicianOptions = $orderFields['technician_id']->getOptions();
    $serviceField = collect($orderFields)->first(
        fn ($field, string $key): bool => str_ends_with($key, '.service_id'),
    );
    $serviceOptions = $serviceField->getOptions();

    expect($hasOption($orderClientOptions, $ownClient->id))->toBeTrue()
        ->and($hasOption($orderClientOptions, $otherClient->id))->toBeFalse()
        ->and($hasOption($technicianOptions, $ownTechnician->id))->toBeTrue()
        ->and($hasOption($technicianOptions, $inactiveTechnician->id))->toBeFalse()
        ->and($hasOption($technicianOptions, $otherTechnician->id))->toBeFalse()
        ->and($hasOption($serviceOptions, $ownService->id))->toBeTrue()
        ->and($hasOption($serviceOptions, $otherService->id))->toBeFalse()
        ->and($orderFields['status']->getOptions())->toHaveKeys(['draft', 'scheduled', 'in_progress', 'completed', 'billed', 'canceled']);

    $this->actingAs($this->admin);

    $adminClientFields = Livewire::test(CreateClient::class)->instance()->form->getFlatFields(withHidden: true);
    $adminUserOptions = $adminClientFields['user_id']->getOptions();
    $settingFields = Livewire::test(CreateFocusNfeSetting::class)->instance()->form->getFlatFields(withHidden: true);
    $userFields = Livewire::test(CreateUser::class)->instance()->form->getFlatFields(withHidden: true);

    expect($hasOption($adminUserOptions, $this->enterprise->id))->toBeTrue()
        ->and($hasOption($adminUserOptions, $this->otherEnterprise->id))->toBeTrue()
        ->and($settingFields['settings.base_url']->getOptions())->toHaveKeys([
            'https://homologacao.focusnfe.com.br',
            'https://api.focusnfe.com.br',
        ])
        ->and($settingFields['settings.nfe.emitente.regime_tributario']->getOptions())->toHaveKeys(['1', '2', '3'])
        ->and($userFields['role']->getOptions())->toHaveKeys(['admin', 'enterprise']);

    $this->actingAs($this->enterprise);

    Livewire::test(ListClients::class)
        ->assertCanSeeTableRecords([$ownClient])
        ->assertCanNotSeeTableRecords([$otherClient]);
});

test('seleção de produto e serviço preenche os campos derivados dos repetidores', function (): void {
    $product = Product::factory()->create([
        'user_id' => $this->enterprise->id,
        'name' => 'Produto selecionável',
        'sale_price' => 125.50,
        'unit' => 'UN',
    ]);
    $service = Service::factory()->create([
        'user_id' => $this->enterprise->id,
        'name' => 'Serviço selecionável',
        'code' => 'SERV-SELECT-001',
        'unit_price' => 300,
        'unit' => 'HR',
    ]);

    $this->actingAs($this->enterprise);

    $salePage = Livewire::test(CreateSale::class);
    $saleKey = array_key_first($salePage->instance()->form->getRawState()['saleItems']);
    $salePath = 'data.saleItems.'.$saleKey;
    $salePage->set($salePath.'.product_id', $product->id);

    $salePage->assertSet($salePath.'.product_name', $product->name)
        ->assertSet($salePath.'.product_code', (string) $product->id)
        ->assertSet($salePath.'.unit', $product->unit)
        ->assertSet($salePath.'.unit_price', $product->sale_price);

    $orderPage = Livewire::test(CreateServiceOrder::class);
    $orderKey = array_key_first($orderPage->instance()->form->getRawState()['items']);
    $orderPath = 'data.items.'.$orderKey;
    $orderPage->set($orderPath.'.service_id', $service->id);

    $orderPage->assertSet($orderPath.'.service_name', $service->name)
        ->assertSet($orderPath.'.service_code', $service->code)
        ->assertSet($orderPath.'.unit', $service->unit)
        ->assertSet($orderPath.'.unit_price', $service->unit_price);
});

test('botões de criação persistem dados e aplicam as regras dos formulários', function (): void {
    $this->actingAs($this->enterprise);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'Cliente criado pelo painel',
            'email' => 'cliente-painel@example.test',
            'phone' => '11999998888',
            'document_type' => 'cpf',
            'document' => '529.982.247-25',
            'address' => 'Rua do Painel',
            'address_number' => '100',
            'city' => 'São Paulo',
            'ibge_code' => '3550308',
            'state' => 'SP',
            'zip_code' => '01311000',
            'country' => 'BR',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $client = Client::query()->where('email', 'cliente-painel@example.test')->firstOrFail();

    expect($client->user_id)->toBe($this->enterprise->id)
        ->and($client->document)->toBe('52998224725');

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'sku' => 'produto-painel-001',
            'barcode' => '78912345',
            'name' => 'Produto criado pelo painel',
            'ncm_code' => '61091000',
            'cest_code' => '1234567',
            'gtin' => '78912345',
            'unit' => 'un',
            'cost_price' => 50,
            'sale_price' => 99.90,
            'stock_quantity' => 20,
            'minimum_stock' => 2,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $product = Product::query()->where('name', 'Produto criado pelo painel')->firstOrFail();

    expect($product->user_id)->toBe($this->enterprise->id)
        ->and($product->sku)->toBe('PRODUTO-PAINEL-001')
        ->and($product->unit)->toBe('UN');

    Livewire::test(CreateService::class)
        ->fillForm([
            'code' => 'SERV-PAINEL-001',
            'name' => 'Serviço criado pelo painel',
            'unit' => 'HR',
            'unit_price' => 250,
            'municipal_service_code' => '0107',
            'lc116_code' => '0107',
            'iss_aliquot' => 5,
            'pis_aliquot' => 0,
            'cofins_aliquot' => 0,
            'inss_aliquot' => 0,
            'ir_aliquot' => 0,
            'csll_aliquot' => 0,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    expect(Service::query()->where('code', 'SERV-PAINEL-001')->value('user_id'))
        ->toBe($this->enterprise->id);

    Livewire::test(CreateTechnician::class)
        ->fillForm([
            'name' => 'Técnico criado pelo painel',
            'phone' => '21988887777',
            'email' => 'tecnico-painel@example.test',
            'notes' => 'Disponível para atendimento.',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    expect(Technician::query()->where('email', 'tecnico-painel@example.test')->value('user_id'))
        ->toBe($this->enterprise->id);

    $movementProduct = Product::factory()->create([
        'user_id' => $this->enterprise->id,
        'stock_quantity' => 10,
    ]);

    Livewire::test(CreateStockMovement::class)
        ->fillForm([
            'product_id' => $movementProduct->id,
            'movement_type' => 'entry',
            'source_type' => 'manual',
            'reference' => 'UI-MOV-001',
            'quantity' => 3,
            'previous_stock' => 10,
            'current_stock' => 13,
            'unit_cost' => 25,
            'moved_at' => now()->subMinute()->format('Y-m-d H:i'),
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    expect(StockMovement::query()->where('reference', 'UI-MOV-001')->value('user_id'))
        ->toBe($this->enterprise->id);

    $orderClient = Client::factory()->create([
        'user_id' => $this->enterprise->id,
        'document_type' => 'nif',
        'document' => 'NIF-UI-001',
    ]);
    $orderService = Service::factory()->create([
        'user_id' => $this->enterprise->id,
        'unit_price' => 400,
    ]);
    $orderTechnician = Technician::factory()->create(['user_id' => $this->enterprise->id]);

    Livewire::test(CreateServiceOrder::class)
        ->fillForm([
            'client_id' => $orderClient->id,
            'technician_id' => $orderTechnician->id,
            'status' => 'draft',
            'items' => [[
                'service_id' => $orderService->id,
                'service_name' => $orderService->name,
                'service_code' => $orderService->code,
                'description' => $orderService->description,
                'unit' => $orderService->unit,
                'quantity' => 2,
                'unit_price' => 400,
                'total_amount' => 800,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $order = ServiceOrder::query()->where('client_id', $orderClient->id)->firstOrFail();

    expect($order->user_id)->toBe($this->enterprise->id)
        ->and($order->technician_id)->toBe($orderTechnician->id)
        ->and((float) $order->total_amount)->toBe(800.0);

    $saleClient = Client::factory()->create([
        'user_id' => $this->enterprise->id,
        'document_type' => 'nif',
        'document' => 'NIF-UI-002',
    ]);
    $saleProduct = Product::factory()->create([
        'user_id' => $this->enterprise->id,
        'sale_price' => 75,
        'stock_quantity' => 20,
    ]);

    Livewire::test(CreateSale::class)
        ->fillForm([
            'client_id' => $saleClient->id,
            'sale_date' => today()->toDateString(),
            'status' => 'completed',
            'payment_status' => 'paid',
            'issue_invoice' => false,
            'saleItems' => [[
                'product_id' => $saleProduct->id,
                'product_name' => $saleProduct->name,
                'product_code' => (string) $saleProduct->id,
                'unit' => 'UN',
                'quantity' => 2,
                'unit_price' => 75,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 150,
            ]],
            'subtotal_amount' => 150,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 150,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $sale = Sale::query()->where('client_id', $saleClient->id)->firstOrFail();

    expect($sale->user_id)->toBe($this->enterprise->id)
        ->and($sale->saleItems()->count())->toBe(1)
        ->and((float) $sale->total_amount)->toBe(150.0);
});

test('ações do painel enfileiram os trabalhos corretos', function (): void {
    Bus::fake();

    $client = Client::factory()->create(['user_id' => $this->enterprise->id]);
    $sale = Sale::factory()->create([
        'user_id' => $this->enterprise->id,
        'client_id' => $client->id,
        'focus_nfe_status' => null,
    ]);
    $order = ServiceOrder::factory()->create([
        'user_id' => $this->enterprise->id,
        'client_id' => $client->id,
        'status' => 'completed',
        'focus_nfse_status' => null,
    ]);

    $this->actingAs($this->enterprise);

    Livewire::test(EditSale::class, ['record' => $sale->getRouteKey()])
        ->assertActionVisible('previsualizar_danfe')
        ->assertActionHasUrl('previsualizar_danfe', route('sales.danfe-preview', $sale))
        ->assertActionShouldOpenUrlInNewTab('previsualizar_danfe')
        ->assertActionVisible('emitir_nfe')
        ->callAction('emitir_nfe');

    Bus::assertDispatched(EmitSaleNfeJob::class, fn (EmitSaleNfeJob $job): bool => $job->saleId === $sale->id);

    $sale->update([
        'focus_nfe_ref' => 'ui-nfe-ref-001',
        'focus_nfe_status' => NfeStatus::PROCESSING,
    ]);

    Livewire::test(EditSale::class, ['record' => $sale->getRouteKey()])
        ->assertActionVisible('consultar_nfe')
        ->callAction('consultar_nfe');

    Bus::assertDispatched(ConsultSaleNfeJob::class, fn (ConsultSaleNfeJob $job): bool => $job->saleId === $sale->id);

    $sale->update(['focus_nfe_status' => NfeStatus::AUTHORIZED]);

    Livewire::test(EditSale::class, ['record' => $sale->getRouteKey()])
        ->assertActionVisible('cancelar_nfe')
        ->callAction('cancelar_nfe', ['justification' => 'Cancelamento solicitado pelo cliente.']);

    Bus::assertDispatched(CancelSaleNfeJob::class, function (CancelSaleNfeJob $job) use ($sale): bool {
        return $job->saleId === $sale->id && $job->justification === 'Cancelamento solicitado pelo cliente.';
    });

    Livewire::test(ListServiceOrders::class)
        ->assertTableActionVisible('emitir_nfse', $order)
        ->callTableAction('emitir_nfse', $order);

    Bus::assertDispatched(EmitServiceNfseJob::class, fn (EmitServiceNfseJob $job): bool => $job->serviceId === $order->id);

    $order->update(['focus_nfse_status' => NfseStatus::PROCESSING]);

    Livewire::test(ListServiceOrders::class)
        ->assertTableActionVisible('consultar_nfse', $order)
        ->callTableAction('consultar_nfse', $order);

    Bus::assertDispatched(ConsultServiceNfseJob::class, fn (ConsultServiceNfseJob $job): bool => $job->serviceId === $order->id);

    $order->update(['focus_nfse_status' => NfseStatus::AUTHORIZED]);

    Livewire::test(ListServiceOrders::class)
        ->assertTableActionVisible('cancelar_nfse', $order)
        ->callTableAction('cancelar_nfse', $order);

    Bus::assertDispatched(CancelServiceNfseJob::class, fn (CancelServiceNfseJob $job): bool => $job->serviceId === $order->id);
});

test('botões de importação fiscal validam dados e enfileiram jobs', function (): void {
    Bus::fake();
    $this->actingAs($this->enterprise);

    Livewire::test(ListFiscalDocuments::class)
        ->callAction('importar_focus_nfe', ['month' => '202601'])
        ->callAction('importar_focus_nfse', ['reference' => 'nfse-ui-ref-001']);

    Bus::assertDispatched(ImportFocusNfeBackupJob::class, function (ImportFocusNfeBackupJob $job): bool {
        return $job->userId === $this->enterprise->id && $job->month === '202601';
    });
    Bus::assertDispatched(ImportFocusNfseByReferenceJob::class, function (ImportFocusNfseByReferenceJob $job): bool {
        return $job->userId === $this->enterprise->id && $job->reference === 'nfse-ui-ref-001';
    });
});
