<?php

use App\Filament\Resources\SaleResource;
use App\Filament\Resources\SaleResource\Pages\EditSale;
use App\Filament\Resources\SaleResource\Pages\ListSales;
use App\Filament\Resources\SaleResource\RelationManagers\SaleItemsRelationManager;
use App\Jobs\ConsultSaleNfeJob;
use App\Models\FiscalConnection;
use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Models\User;
use App\Support\NfeStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['role' => 'enterprise']);
    $this->sale = Sale::factory()->create(['user_id' => $this->user->id]);
    $this->actingAs($this->user);
});

test('venda com NF-e autorizada bloqueia cliente, valores e itens', function (): void {
    $this->sale->forceFill(['focus_nfe_status' => NfeStatus::AUTHORIZED, 'focus_nfe_ref' => 'sale1x'])->saveQuietly();

    Livewire::test(EditSale::class, ['record' => $this->sale->getRouteKey()])
        ->assertFormFieldIsDisabled('client_id')
        ->assertFormFieldIsDisabled('discount_amount');

    expect(Livewire::test(SaleItemsRelationManager::class, ['ownerRecord' => $this->sale, 'pageClass' => EditSale::class])->instance()->isReadOnly())->toBeTrue();
});

test('venda sem NF-e continua editável', function (): void {
    Livewire::test(EditSale::class, ['record' => $this->sale->getRouteKey()])
        ->assertFormFieldIsEnabled('client_id');
});

test('rejeição mostra o motivo da SEFAZ na lista', function (): void {
    $this->sale->forceFill([
        'focus_nfe_status' => NfeStatus::AUTHORIZATION_ERROR, 'focus_nfe_ref' => 'sale1y',
        'focus_nfe_connection_id' => 1, 'focus_nfe_environment' => 'homologacao',
        'focus_nfe_error' => ['response' => ['mensagem_sefaz' => 'Rejeição 539: Duplicidade de NF-e']],
    ])->saveQuietly();

    Livewire::test(ListSales::class)->assertSee('Rejeitada')->assertSee('Rejeição 539')
        ->assertTableActionVisible('ver_problema', $this->sale);
});

test('consulta manual não libera o lock único da consulta enfileirada', function (): void {
    $document = FiscalDocument::factory()->create([
        'source_type' => Sale::class, 'source_id' => $this->sale->id, 'company_id' => $this->sale->company_id,
        'fiscal_connection_id' => FiscalConnection::query()->where('company_id', $this->sale->company_id)->value('id') ?? FiscalConnection::factory()->create(['company_id' => $this->sale->company_id])->id,
        'focus_reference' => 'sale1lock', 'environment' => 'homologacao', 'status' => NfeStatus::PROCESSING,
    ]);
    $this->sale->forceFill(['focus_nfe_ref' => 'sale1lock', 'focus_nfe_connection_id' => $document->fiscal_connection_id, 'focus_nfe_environment' => 'homologacao', 'focus_nfe_status' => NfeStatus::PROCESSING])->saveQuietly();
    $unique = Cache::lock('laravel_unique_job:'.ConsultSaleNfeJob::class.':document:'.$document->id, 3600);
    expect($unique->get())->toBeTrue();
    Http::fake(['*' => Http::response(['erro' => 'x'], 500)]);

    SaleResource::consultNow($this->sale);

    expect(Cache::lock('laravel_unique_job:'.ConsultSaleNfeJob::class.':document:'.$document->id, 10)->get())->toBeFalse();
});

test('criar venda pelo formulário aceita desconto e impostos em branco', function (): void {
    $product = App\Models\Product::factory()->create(['user_id' => $this->user->id, 'company_id' => $this->sale->company_id, 'stock_quantity' => 10, 'sale_price' => 20]);
    $client = App\Models\Client::factory()->create(['user_id' => $this->user->id, 'company_id' => $this->sale->company_id]);

    Livewire::test(App\Filament\Resources\SaleResource\Pages\CreateSale::class)
        ->fillForm([
            'company_id' => $this->sale->company_id, 'client_id' => $client->id,
            'discount_amount' => null, 'tax_amount' => null,
            'saleItems' => [['product_id' => $product->id, 'product_name' => $product->name, 'product_code' => (string) $product->id, 'unit' => 'UN', 'quantity' => 2, 'unit_price' => 20, 'discount_amount' => null, 'tax_amount' => null, 'total_amount' => 40]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect((float) Sale::query()->latest('id')->first()->total_amount)->toBe(40.0);
});

test('venda sem cliente é recusada com mensagem clara', function (): void {
    Livewire::test(App\Filament\Resources\SaleResource\Pages\CreateSale::class)
        ->fillForm(['client_id' => null])
        ->call('create')
        ->assertHasFormErrors(['client_id' => 'required']);
});
