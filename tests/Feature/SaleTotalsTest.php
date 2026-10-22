<?php

use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('calculates item and sale totals through creation updates and deletion', function () {
    $sale = Sale::factory()->create(['discount_amount' => 5, 'tax_amount' => 2]);
    $item = SaleItem::factory()->create([
        'sale_id' => $sale->id,
        'quantity' => 1,
        'unit_price' => 50,
        'total_amount' => 0,
    ]);

    expect($item->fresh()->total_amount)->toBe('50.00')
        ->and($sale->fresh()->subtotal_amount)->toBe('50.00')
        ->and($sale->fresh()->total_amount)->toBe('47.00');

    $item->update(['quantity' => 2, 'discount_amount' => 10, 'tax_amount' => 3]);
    expect($item->fresh()->total_amount)->toBe('93.00')
        ->and($sale->fresh()->subtotal_amount)->toBe('93.00');

    $sale->refresh()->update(['discount_amount' => 8, 'subtotal_amount' => 999, 'total_amount' => 999]);
    expect($sale->fresh()->total_amount)->toBe('87.00');

    $item->delete();
    expect($sale->fresh()->subtotal_amount)->toBe('0.00')
        ->and($sale->fresh()->total_amount)->toBe('0.00');
});
