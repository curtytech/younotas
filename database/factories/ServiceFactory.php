<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'code' => 'SERVICO-TESTE',
            'name' => 'Desenvolvimento de software',
            'description' => 'Serviço criado para teste de emissão de NFS-e.',
            'municipal_service_code' => '0107',
            'lc116_code' => '0107',
            'cnae_code' => '6201501',
            'nbs_code' => null,
            'unit' => 'UN',
            'unit_price' => 1500,
            'iss_aliquot' => 5,
            'pis_aliquot' => 0,
            'cofins_aliquot' => 0,
            'inss_aliquot' => 0,
            'ir_aliquot' => 0,
            'csll_aliquot' => 0,
            'is_active' => true,
        ];
    }
}
