<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    private function uniqueCpf(): string
    {
        $digits = (string) fake()->unique()->numberBetween(100000000, 999999999);
        for ($length = 9; $length < 11; $length++) {
            $sum = 0;
            for ($index = 0; $index < $length; $index++) {
                $sum += (int) $digits[$index] * ($length + 1 - $index);
            }
            $remainder = ($sum * 10) % 11;
            $digits .= $remainder === 10 ? '0' : (string) $remainder;
        }
        return $digits;
    }

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Cliente '.fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '11987654321',
            'document_type' => 'cpf',
            'document' => $this->uniqueCpf(),
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
