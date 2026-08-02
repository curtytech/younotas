<?php

namespace Database\Seeders;

use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Client;
use App\Models\Driver;
use App\Models\Fine;
use App\Models\FocusNfeWebhookEvent;
use App\Models\FocusNfseWebhookEvent;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderAttachment;
use App\Models\ServiceOrderItem;
use App\Models\StockMovement;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class AllTablesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['test@example.com', 'mil.mage.teste@example.com'] as $email) {
            $user = User::where('email', $email)->first();

            if ($user) {
                $this->seedForUser($user);
            }
        }
    }

    private function seedForUser(User $user): void
    {

        $client = Client::firstOrCreate(
            ['user_id' => $user->id, 'email' => 'cliente.seed@example.com'],
            Client::factory()->make(['user_id' => $user->id, 'email' => 'cliente.seed@example.com'])->toArray(),
        );
        $product = Product::firstOrCreate(['user_id' => $user->id, 'sku' => 'PROD-SEED-001'], Product::factory()->make(['user_id' => $user->id, 'sku' => 'PROD-SEED-001'])->toArray());
        $service = Service::firstOrCreate(['user_id' => $user->id, 'code' => 'SERV-SEED-001'], Service::factory()->make(['user_id' => $user->id, 'code' => 'SERV-SEED-001'])->toArray());
        Technician::firstOrCreate(['user_id' => $user->id, 'email' => 'tecnico.seed@example.com'], Technician::factory()->make(['user_id' => $user->id, 'email' => 'tecnico.seed@example.com'])->toArray());
        $sale = Sale::firstOrCreate(['user_id' => $user->id, 'number' => 'SALE-SEED-000001'], Sale::factory()->make(['user_id' => $user->id, 'client_id' => $client->id, 'number' => 'SALE-SEED-000001'])->toArray());
        SaleItem::firstOrCreate(['sale_id' => $sale->id, 'product_id' => $product->id], SaleItem::factory()->make(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name, 'product_code' => (string) $product->id])->toArray());
        StockMovement::firstOrCreate(['user_id' => $user->id, 'product_id' => $product->id, 'reference' => 'STOCK-SEED-001'], StockMovement::factory()->make(['user_id' => $user->id, 'product_id' => $product->id, 'reference' => 'STOCK-SEED-001'])->toArray());
        $order = ServiceOrder::firstOrCreate(['user_id' => $user->id, 'number' => 'OS-SEED-000001'], ['client_id' => $client->id, 'status' => 'draft']);
        if (! $order->items()->exists()) {
            ServiceOrderItem::create(['service_order_id' => $order->id, 'service_id' => $service->id, 'service_name' => $service->name, 'service_code' => $service->code, 'description' => $service->description, 'municipal_service_code' => $service->municipal_service_code, 'lc116_code' => $service->lc116_code, 'unit' => $service->unit, 'quantity' => 1, 'unit_price' => $service->unit_price, 'total_amount' => $service->unit_price, 'iss_aliquot' => $service->iss_aliquot]);
        }
        ServiceOrderAttachment::firstOrCreate(['service_order_id' => $order->id, 'path' => 'service-orders/seed/readme.txt'], ['original_name' => 'readme.txt', 'mime_type' => 'text/plain', 'size' => 0, 'uploaded_by' => $user->id]);

        $vehicle = Vehicle::firstOrCreate(['user_id' => $user->id, 'plate' => 'RJD1E23'], Vehicle::factory()->make(['user_id' => $user->id, 'plate' => 'RJD1E23'])->toArray());
        $driver = Driver::firstOrCreate(['user_id' => $user->id, 'cnh' => '12345678900'], Driver::factory()->make(['user_id' => $user->id, 'vehicle_id' => $vehicle->id, 'cnh' => '12345678900'])->toArray());
        $fine = Fine::firstOrCreate(['user_id' => $user->id, 'ait' => 'AIT-SEED-0001'], Fine::factory()->make(['user_id' => $user->id, 'vehicle_id' => $vehicle->id, 'ait' => 'AIT-SEED-0001'])->toArray());
        $appealStatus = AppealStatus::firstOrCreate(['name' => 'Pendente']);
        Appeal::firstOrCreate(['user_id' => $user->id, 'fine_id' => $fine->id], ['appeal_status_id' => $appealStatus->id, 'date' => now()->toDateString()]);

        $nfePayload = ['ref' => 'seed-nfe-webhook-001', 'status' => 'processando'];
        FocusNfeWebhookEvent::firstOrCreate(
            ['payload_hash' => hash('sha256', json_encode($nfePayload))],
            ['sale_id' => $sale->id, 'reference' => $nfePayload['ref'], 'payload' => $nfePayload],
        );

        $nfsePayload = ['ref' => 'seed-nfse-webhook-001', 'status' => 'processando'];
        FocusNfseWebhookEvent::firstOrCreate(
            ['payload_hash' => hash('sha256', json_encode($nfsePayload))],
            ['service_order_id' => $order->id, 'reference' => $nfsePayload['ref'], 'payload' => $nfsePayload],
        );
    }
}
