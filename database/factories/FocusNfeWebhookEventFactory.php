<?php
namespace Database\Factories;
use App\Models\FocusNfeWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<FocusNfeWebhookEvent> */
class FocusNfeWebhookEventFactory extends Factory { protected $model = FocusNfeWebhookEvent::class; public function definition(): array { $payload = ['ref' => fake()->uuid(), 'status' => 'processando']; return ['sale_id' => null, 'reference' => $payload['ref'], 'payload_hash' => hash('sha256', json_encode($payload)), 'payload' => $payload, 'processed_at' => null]; } }
