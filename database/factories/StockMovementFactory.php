<?php
namespace Database\Factories;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<StockMovement> */
class StockMovementFactory extends Factory { protected $model = StockMovement::class; public function definition(): array { return ['user_id' => User::factory(), 'product_id' => fn (array $attributes) => Product::factory()->create(['user_id' => $attributes['user_id'], 'company_id' => $attributes['company_id'] ?? null])->id, 'movement_type' => 'entry', 'source_type' => 'manual', 'reference' => fake()->bothify('MOV-####'), 'quantity' => 1, 'previous_stock' => 0, 'current_stock' => 1, 'unit_cost' => 10, 'notes' => fake()->sentence(), 'moved_at' => now()]; } }
