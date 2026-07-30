<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceOrder> */
class ServiceOrderFactory extends Factory
{
    protected $model = ServiceOrder::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'client_id' => Client::factory(), 'number' => 'OS-'.fake()->unique()->numerify('######'), 'status' => 'draft', 'total_amount' => 0];
    }
}
