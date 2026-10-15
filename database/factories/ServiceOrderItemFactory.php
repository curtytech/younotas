<?php

namespace Database\Factories;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceOrderItem> */
class ServiceOrderItemFactory extends Factory
{
    protected $model = ServiceOrderItem::class;

    public function definition(): array
    {
        return ['service_order_id' => ServiceOrder::factory(), 'service_name' => 'Serviço de teste', 'unit' => 'UN', 'quantity' => 1, 'unit_price' => 100, 'total_amount' => 100];
    }
}
