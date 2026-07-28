<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'sku' => 'CAMISETA-TESTE',
            'name' => 'Camiseta de teste',
            'description' => 'Produto criado para teste de emissão de NF-e.',
            'ncm_code' => '61091000',
            'cfop_code' => '5102',
            'gtin' => null,
            'unit' => 'UN',
            'cost_price' => 50,
            'sale_price' => 99.90,
            'stock_quantity' => 100,
            'minimum_stock' => 1,
            'icms_aliquot' => 0,
            'ipi_aliquot' => 0,
            'pis_aliquot' => 0,
            'cofins_aliquot' => 0,
            'is_active' => true,
        ];
    }
}
