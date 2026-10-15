<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\FiscalConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FiscalConnection> */
class FiscalConnectionFactory extends Factory
{
    protected $model = FiscalConnection::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'provider' => 'focus',
            'environment' => 'homologacao',
            'api_key' => 'test-focus-'.fake()->uuid(),
            'enabled' => true,
            'settings' => [],
        ];
    }

    public function production(): static
    {
        return $this->state(fn (): array => ['environment' => 'producao']);
    }
}
