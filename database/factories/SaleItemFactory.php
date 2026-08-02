<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SaleItem> */
class SaleItemFactory extends Factory
{
    protected $model = SaleItem::class;

    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'product_id' => Product::factory(),
            'product_name' => 'Item de teste',
            'product_code' => '1',
            'unit' => 'UN',
            'quantity' => 1,
            'unit_price' => 99.90,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 99.90,
        ];
    }
}
