<?php
namespace Database\Factories;
use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Fine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<Appeal> */
class AppealFactory extends Factory { protected $model = Appeal::class; public function definition(): array { return ['user_id' => User::factory(), 'fine_id' => Fine::factory(), 'appeal_status_id' => AppealStatus::factory(), 'date' => fake()->date()]; } }
