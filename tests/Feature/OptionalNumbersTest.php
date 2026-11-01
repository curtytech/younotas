<?php

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\StockMovementResource\Pages\CreateStockMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('produto aceita números opcionais apagados na criação e edição', function ($page) {
    $user = User::factory()->create(['role' => 'enterprise']);
    $this->actingAs($user);
    $fields = ['cost_price', 'sale_price', 'stock_quantity', 'minimum_stock', 'icms_aliquot', 'ipi_aliquot', 'pis_aliquot', 'cofins_aliquot'];
    $record = $page === EditProduct::class ? Product::factory()->create(['user_id' => $user->id]) : null;

    Livewire::test($page, $record ? ['record' => $record->getRouteKey()] : [])
        ->fillForm(['name' => 'Produto com campos apagados', ...array_fill_keys($fields, null)])
        ->call($record ? 'save' : 'create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('user_id', $user->id)->sole();
    foreach ($fields as $field) {
        expect((float) $product->{$field})->toBe(0.0);
    }
})->with([[CreateProduct::class], [EditProduct::class]]);

test('movimentação de estoque aceita saldos e custo em branco', function () {
    $user = User::factory()->create(['role' => 'enterprise']);
    $product = Product::factory()->create(['user_id' => $user->id, 'stock_quantity' => 10]);
    $this->actingAs($user);

    Livewire::test(CreateStockMovement::class)
        ->fillForm([
            'product_id' => $product->id,
            'movement_type' => 'entry',
            'source_type' => 'manual',
            'quantity' => 2,
            'reference' => 'REGRESSAO-CAMPOS-VAZIOS',
            'previous_stock' => null,
            'current_stock' => null,
            'unit_cost' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $movement = StockMovement::query()->where('reference', 'REGRESSAO-CAMPOS-VAZIOS')->sole();
    expect($product->fresh()->stock_quantity)->toBe('12.000')
        ->and($movement->previous_stock)->toBe('10.000')
        ->and($movement->current_stock)->toBe('12.000')
        ->and($movement->unit_cost)->toBe('0.00');
});

test('venda e item aceitam desconto e impostos em branco sem perder valores preenchidos', function ($model) {
    $record = $model::factory()->create(['discount_amount' => null, 'tax_amount' => null]);
    $record->refresh();
    expect($record->discount_amount)->toBe('0.00')
        ->and($record->tax_amount)->toBe('0.00');

    $record->update(['discount_amount' => 2.35, 'tax_amount' => 1.25]);
    $record->refresh();
    expect($record->discount_amount)->toBe('2.35')
        ->and($record->tax_amount)->toBe('1.25');

    $record->update(['discount_amount' => '', 'tax_amount' => null]);
    $record->refresh();
    expect($record->discount_amount)->toBe('0.00')
        ->and($record->tax_amount)->toBe('0.00');
})->with([[Sale::class], [SaleItem::class]]);
