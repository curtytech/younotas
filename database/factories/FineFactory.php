<?php
namespace Database\Factories;
use App\Models\Fine;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<Fine> */
class FineFactory extends Factory { protected $model = Fine::class; public function definition(): array { return ['user_id' => User::factory(), 'vehicle_id' => Vehicle::factory(), 'ait' => strtoupper(fake()->bothify('AIT-########')), 'fine_date' => fake()->date(), 'description' => fake()->sentence(), 'fine_article' => fake()->numerify('Art. ##')]; } }
