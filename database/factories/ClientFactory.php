<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Cliente '.fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '11987654321',
            'document_type' => 'cpf',
            'document' => '52998224725',
            'address' => 'Avenida Paulista',
            'address_number' => '1000',
            'address_complement' => 'Sala 10',
            'neighborhood' => 'Bela Vista',
            'city' => 'São Paulo',
            'ibge_code' => '3550308',
            'state' => 'SP',
            'zip_code' => '01311000',
            'country' => 'BR',
            'is_active' => true,
        ];
    }
}
