<?php

use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\CompanyResource\Pages\CreateCompany;
use App\Filament\Resources\CompanyResource\Pages\EditCompany;
use App\Filament\Resources\FocusNfeSettingResource\Pages\CreateFocusNfeSetting;
use App\Filament\Resources\SaleResource\Pages\CreateSale;
use App\Filament\Resources\ServiceOrderResource\Pages\CreateServiceOrder;
use App\Filament\Resources\ServiceOrderResource\Pages\EditServiceOrder;
use App\Models\Client;
use App\Models\Company;
use App\Models\FocusNfeSetting;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['role' => 'enterprise']);
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->user);

    Http::fake([
        'https://viacep.com.br/ws/*' => Http::response([
            'logradouro' => 'Avenida Paulista',
            'bairro' => 'Bela Vista',
            'localidade' => 'São Paulo',
            'uf' => 'SP',
            'ibge' => '3550308',
        ]),
    ]);
});

function validClientData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Cliente da validação',
        'email' => 'validacao@example.test',
        'phone' => '11999998888',
        'document_type' => 'cpf',
        'document' => '529.982.247-25',
        'address' => 'Rua da Validação',
        'address_number' => '100',
        'city' => 'São Paulo',
        'ibge_code' => '3550308',
        'state' => 'SP',
        'zip_code' => '01311000',
        'country' => 'BR',
        'is_active' => true,
    ], $overrides);
}

test('cliente salva UF e CEP normalizados', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm(validClientData(['state' => 'sp', 'zip_code' => '01311-000']))
        ->call('create')
        ->assertHasNoFormErrors();

    $client = Client::query()->where('email', 'validacao@example.test')->firstOrFail();

    expect($client->state)->toBe('SP')
        ->and($client->zip_code)->toBe('01311000');
});

test('cliente brasileiro exige UF e código IBGE', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm(validClientData(['state' => null, 'ibge_code' => null, 'zip_code' => null]))
        ->call('create')
        ->assertHasFormErrors(['state', 'ibge_code']);
});

test('cliente recusa UF e código IBGE inválidos', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm(validClientData(['state' => 'ZZ', 'ibge_code' => '123', 'zip_code' => null]))
        ->call('create')
        ->assertHasFormErrors([
            'state' => 'Informe uma UF válida.',
            'ibge_code' => 'O código IBGE deve ter 7 dígitos.',
        ]);
});

test('cliente recusa CEP com quantidade de dígitos diferente de oito', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm(validClientData(['zip_code' => '0131-100']))
        ->call('create')
        ->assertHasFormErrors(['zip_code' => 'O CEP deve conter 8 dígitos.']);
});

test('cliente de outro país pode ficar sem UF e código IBGE', function (): void {
    Livewire::test(CreateClient::class)
        ->fillForm(validClientData([
            'country' => 'PT',
            'state' => null,
            'ibge_code' => null,
            'zip_code' => null,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();
});

test('empresa recusa CNPJ inválido e salva CNPJ formatado sem máscara', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateCompany::class)
        ->fillForm(['name' => 'Empresa Validação', 'cnpj' => '04.252.011/0001-11'])
        ->call('create')
        ->assertHasFormErrors(['cnpj' => 'Informe um CNPJ válido.']);

    Livewire::test(CreateCompany::class)
        ->fillForm(['name' => 'Empresa Validação', 'cnpj' => '04.252.011/0001-10'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Company::query()->where('name', 'Empresa Validação')->value('cnpj'))->toBe('04252011000110');
});

test('empresa valida UF e CEP', function (): void {
    $this->actingAs($this->admin);

    $company = Company::factory()->create(['name' => 'Empresa Endereço']);

    Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
        ->fillForm(['state' => 'ZZ', 'zip_code' => '0131-10'])
        ->call('save')
        ->assertHasFormErrors([
            'state' => 'Informe uma UF válida.',
            'zip_code' => 'O CEP deve conter 8 dígitos.',
        ]);

    Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
        ->fillForm(['state' => 'sp', 'zip_code' => '01305-000'])
        ->call('save')
        ->assertHasNoFormErrors();

    $company->refresh();

    expect($company->state)->toBe('SP')
        ->and($company->zip_code)->toBe('01305000');
});

test('venda recusa desconto maior que o subtotal', function (): void {
    $client = Client::factory()->create(['user_id' => $this->user->id]);
    $product = Product::factory()->create(['user_id' => $this->user->id, 'sale_price' => 10, 'stock_quantity' => 10]);

    Livewire::test(CreateSale::class)
        ->fillForm([
            'client_id' => $client->id,
            'sale_date' => today()->toDateString(),
            'status' => 'draft',
            'payment_status' => 'pending',
            'discount_amount' => 50,
            'tax_amount' => 0,
            'saleItems' => [[
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_code' => (string) $product->id,
                'unit' => 'UN',
                'quantity' => 1,
                'unit_price' => 10,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 10,
            ]],
        ])
        ->call('create')
        ->assertHasFormErrors(['discount_amount' => 'O desconto não pode ser maior que o subtotal.']);
});

test('venda aceita desconto dentro do subtotal', function (): void {
    $client = Client::factory()->create(['user_id' => $this->user->id]);
    $product = Product::factory()->create(['user_id' => $this->user->id, 'sale_price' => 10, 'stock_quantity' => 10]);

    Livewire::test(CreateSale::class)
        ->fillForm([
            'client_id' => $client->id,
            'sale_date' => today()->toDateString(),
            'status' => 'draft',
            'payment_status' => 'pending',
            'discount_amount' => 5,
            'tax_amount' => 0,
            'saleItems' => [[
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_code' => (string) $product->id,
                'unit' => 'UN',
                'quantity' => 1,
                'unit_price' => 10,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 10,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect((float) Sale::query()->where('client_id', $client->id)->latest('id')->value('total_amount'))->toBe(5.0);
});

test('total do item da ordem de serviço é calculado a partir da quantidade e do preço', function (): void {
    $client = Client::factory()->create(['user_id' => $this->user->id]);
    $service = Service::factory()->create(['user_id' => $this->user->id, 'unit_price' => 10]);

    Livewire::test(CreateServiceOrder::class)
        ->fillForm([
            'client_id' => $client->id,
            'status' => 'draft',
            'items' => [[
                'service_id' => $service->id,
                'service_name' => $service->name,
                'service_code' => $service->code,
                'unit' => $service->unit,
                'quantity' => 3,
                'unit_price' => 10,
                'total_amount' => 999,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = ServiceOrder::query()->where('client_id', $client->id)->firstOrFail();

    expect((float) $order->items()->value('total_amount'))->toBe(30.0)
        ->and((float) $order->fresh()->total_amount)->toBe(30.0);
});

test('assinatura e nome de quem assinou são obrigatórios em conjunto', function (): void {
    $client = Client::factory()->create(['user_id' => $this->user->id]);
    $service = Service::factory()->create(['user_id' => $this->user->id, 'unit_price' => 10]);

    $data = [
        'client_id' => $client->id,
        'status' => 'draft',
        'items' => [[
            'service_id' => $service->id,
            'service_name' => $service->name,
            'service_code' => $service->code,
            'unit' => $service->unit,
            'quantity' => 1,
            'unit_price' => 10,
        ]],
    ];

    Livewire::test(CreateServiceOrder::class)
        ->fillForm($data + ['signature_path' => [TemporaryUploadedFile::fake()->image('assinatura.png')], 'signed_by_name' => null])
        ->call('create')
        ->assertHasFormErrors(['signed_by_name']);

    Livewire::test(CreateServiceOrder::class)
        ->fillForm($data + ['signed_by_name' => 'Maria Souza'])
        ->call('create')
        ->assertHasFormErrors(['signature_path']);
});

test('transição inválida de status da ordem de serviço aparece como erro do formulário', function (): void {
    $client = Client::factory()->create(['user_id' => $this->user->id]);
    $service = Service::factory()->create(['user_id' => $this->user->id, 'unit_price' => 10]);
    $order = ServiceOrder::factory()->create([
        'user_id' => $this->user->id,
        'client_id' => $client->id,
        'status' => 'scheduled',
    ]);
    $order->items()->create([
        'service_id' => $service->id,
        'service_name' => $service->name,
        'unit' => 'UN',
        'quantity' => 1,
        'unit_price' => 10,
    ]);

    Livewire::test(EditServiceOrder::class, ['record' => $order->getRouteKey()])
        ->set('data.status', 'billed')
        ->call('save')
        ->assertHasFormErrors(['status' => 'Transição de status de O.S. não permitida.']);

    expect($order->fresh()->status)->toBe('scheduled');
});

test('configuração fiscal valida CNPJ, código do município, UF e CEP', function (): void {
    $data = [
        'settings.environment' => 'homologacao',
        'settings.api_keys.homologacao' => 'token-de-teste',
        'settings.prestador.cnpj' => '04.252.011/0001-10',
        'settings.prestador.inscricao_municipal' => '12345678',
        'settings.prestador.codigo_municipio' => '3550308',
        'settings.nfse.natureza_operacao' => '1',
        'settings.nfe.emitente.nome' => 'Empresa Teste NF-e',
        'settings.nfe.emitente.nome_fantasia' => 'Empresa Teste',
        'settings.nfe.emitente.logradouro' => 'Rua Augusta',
        'settings.nfe.emitente.numero' => '500',
        'settings.nfe.emitente.bairro' => 'Consolação',
        'settings.nfe.emitente.municipio' => 'São Paulo',
        'settings.nfe.emitente.uf' => 'sp',
        'settings.nfe.emitente.cep' => '01305-000',
        'settings.nfe.emitente.inscricao_estadual' => '110042490114',
        'settings.nfe.emitente.regime_tributario' => '1',
    ];

    Livewire::test(CreateFocusNfeSetting::class)
        ->fillForm(array_merge($data, [
            'settings.prestador.cnpj' => '04.252.011/0001-11',
            'settings.prestador.codigo_municipio' => '355030',
        ]))
        ->call('create')
        ->assertHasFormErrors([
            'settings.prestador.cnpj' => 'Informe um CNPJ válido.',
            'settings.prestador.codigo_municipio' => 'O código do município deve ter 7 dígitos.',
        ]);

    Livewire::test(CreateFocusNfeSetting::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasNoFormErrors();

    $settings = FocusNfeSetting::query()->latest('id')->firstOrFail()->settings;

    expect(data_get($settings, 'prestador.cnpj'))->toBe('04252011000110')
        ->and(data_get($settings, 'nfe.emitente.uf'))->toBe('SP')
        ->and(data_get($settings, 'nfe.emitente.cep'))->toBe('01305000');
});
