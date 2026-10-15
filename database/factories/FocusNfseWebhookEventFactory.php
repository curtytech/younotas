<?php
namespace Database\Factories;
use App\Models\FocusNfseWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<FocusNfseWebhookEvent> */
class FocusNfseWebhookEventFactory extends Factory { protected $model = FocusNfseWebhookEvent::class; public function definition(): array { $payload = ['ref' => fake()->uuid(), 'status' => 'processando']; return ['service_order_id' => null, 'reference' => $payload['ref'], 'payload_hash' => hash('sha256', json_encode($payload)), 'payload' => $payload, 'processed_at' => null]; } }
