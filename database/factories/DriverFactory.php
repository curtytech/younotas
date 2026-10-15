<?php
namespace Database\Factories;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<Driver> */
class DriverFactory extends Factory { protected $model = Driver::class; public function definition(): array { return ['user_id' => User::factory(), 'vehicle_id' => Vehicle::factory(), 'name' => fake()->name(), 'birth_date' => fake()->date('Y-m-d', '-25 years'), 'description' => fake()->sentence(), 'cpf' => fake()->numerify('###########'), 'cnh' => fake()->numerify('###########'), 'cnh_expiration_date' => fake()->date('Y-m-d', '+2 years'), 'toxicologic_exam_expiration_date' => fake()->date('Y-m-d', '+1 year')]; } }
