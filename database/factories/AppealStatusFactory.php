<?php
namespace Database\Factories;
use App\Models\AppealStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<AppealStatus> */
class AppealStatusFactory extends Factory { protected $model = AppealStatus::class; public function definition(): array { return ['name' => fake()->randomElement(['Pendente', 'Em análise', 'Deferido', 'Indeferido'])]; } }
