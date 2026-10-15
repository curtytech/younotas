<?php
namespace Database\Factories;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<ServiceOrderAttachment> */
class ServiceOrderAttachmentFactory extends Factory { protected $model = ServiceOrderAttachment::class; public function definition(): array { return ['service_order_id' => ServiceOrder::factory(), 'original_name' => 'evidencia.txt', 'path' => 'service-orders/test/evidencia.txt', 'mime_type' => 'text/plain', 'size' => 0, 'uploaded_by' => User::factory()]; } }
