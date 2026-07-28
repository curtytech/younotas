<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Sale> */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'number' => (string) fake()->unique()->numberBetween(1000, 999999),
            'sale_date' => today(),
            'status' => 'completed',
            'payment_status' => 'paid',
            'issue_invoice' => true,
            'subtotal_amount' => 99.90,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 99.90,
        ];
    }
}
