<?php
namespace Database\Factories;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory { protected $model = Vehicle::class; public function definition(): array { return ['user_id' => User::factory(), 'name' => fake()->firstName(), 'plate' => strtoupper(fake()->bothify('???#?##')), 'renavam' => fake()->numerify('#############'), 'model' => fake()->randomElement(['Fiorino', 'Sprinter', 'Master']), 'year' => fake()->numberBetween(2018, 2025), 'type' => 'Utilitário']; } }
