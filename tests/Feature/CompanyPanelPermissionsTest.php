<?php

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\CompanyResource\Pages\EditCompany;
use App\Filament\Resources\FocusNfeSettingResource;
use App\Filament\Resources\FocusNfeSettingResource\Pages\EditFocusNfeSetting;
use App\Models\Client;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FocusNfeSetting;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\CompanyMembershipService;
use App\Services\FocusNfeConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function panelCompanyOwner(): array
{
    $owner = User::factory()->create(['role' => 'enterprise']);
    $setting = FocusNfeSetting::factory()->create(['user_id' => $owner->id]);
    return [$owner, $setting->company, $setting];
}

test('operador vê cadastros da empresa feitos por outra pessoa e não pode alterar credenciais', function (): void {
    [$owner, $company, $setting] = panelCompanyOwner();
    $operator = User::factory()->create(['role' => 'enterprise']);
    $company->members()->attach($operator->id, ['role' => 'operator']);
    $client = Client::factory()->create(['user_id' => $owner->id, 'company_id' => $company->id]);
    $other = Client::factory()->create();
    $this->actingAs($operator);

    expect(ClientResource::getEloquentQuery()->pluck('id')->all())->toContain($client->id)->not->toContain($other->id)
        ->and(ClientResource::canEdit($client))->toBeTrue()
        ->and(FocusNfeSettingResource::canEdit($setting))->toBeFalse();
});

test('perfil consulta não recebe permissões de escrita da empresa', function (): void {
    [$owner, $company] = panelCompanyOwner();
    $viewer = User::factory()->create(['role' => 'enterprise']);
    $company->members()->attach($viewer->id, ['role' => 'viewer']);
    $client = Client::factory()->create(['user_id' => $owner->id, 'company_id' => $company->id]);
    $this->actingAs($viewer);

    expect(ClientResource::canView($client))->toBeTrue()
        ->and(ClientResource::canCreate())->toBeFalse()
        ->and(ClientResource::canEdit($client))->toBeFalse()
        ->and(ClientResource::canDelete($client))->toBeFalse();
});

test('formulário fiscal nunca devolve tokens cadastrados ao navegador', function (): void {
    [$owner, $company, $setting] = panelCompanyOwner();
    $config = app(FocusNfeConfigService::class)->forCompany($company);
    $this->actingAs($owner);
    $page = Livewire::test(EditFocusNfeSetting::class, ['record' => $setting->id])->assertOk();
    $state = json_encode($page->get('data'), JSON_THROW_ON_ERROR);

    expect($state)->not->toContain($config['api_key']);
    $page->assertFormSet(['company_id' => $company->id, 'settings.api_keys.homologacao' => null, 'settings.api_keys.producao' => null]);
});

test('gestão de acessos mantém um proprietário e impede promoção por gestor', function (): void {
    [$owner, $company] = panelCompanyOwner();
    $manager = User::factory()->create(['role' => 'enterprise']);
    $company->members()->attach($manager->id, ['role' => 'manager']);
    $service = app(CompanyMembershipService::class);
    expect(fn () => $service->assign($company, $owner, 'viewer', $owner))->toThrow(ValidationException::class)
        ->and(fn () => $service->assign($company, $manager, 'owner', $manager))->toThrow(ValidationException::class)
        ->and(fn () => $service->remove($company, $owner, $owner))->toThrow(ValidationException::class);
});

test('venda não aceita cliente ou produto de outra empresa', function (): void {
    [$owner, $company] = panelCompanyOwner();
    $otherClient = Client::factory()->create();
    expect(fn () => Sale::factory()->create(['user_id' => $owner->id, 'company_id' => $company->id, 'client_id' => $otherClient->id]))
        ->toThrow(ValidationException::class);

    $client = Client::factory()->create(['user_id' => $owner->id, 'company_id' => $company->id]);
    $sale = Sale::factory()->create(['user_id' => $owner->id, 'company_id' => $company->id, 'client_id' => $client->id]);
    $product = Product::factory()->create(['stock_quantity' => 50]);
    expect(fn () => SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $product->id]))->toThrow(ValidationException::class);
});

test('desativação do acesso conserva empresa e documentos fiscais', function (): void {
    [$owner, $company] = panelCompanyOwner();
    $document = FiscalDocument::factory()->create(['user_id' => $owner->id, 'company_id' => $company->id]);
    $owner->update(['is_active' => false]);

    expect(Company::find($company->id))->not->toBeNull()
        ->and(FiscalDocument::find($document->id))->not->toBeNull()
        ->and($owner->canAccessCompany($company, 'view'))->toBeFalse();
});

test('cadastro real de empresa e gestão de membros carregam para proprietário', function (): void {
    [$owner, $company] = panelCompanyOwner();
    $this->actingAs($owner);
    Livewire::test(EditCompany::class, ['record' => $company->id])->assertOk()->assertFormSet(['name' => $company->name]);
});
