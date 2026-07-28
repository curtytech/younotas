<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\FocusNfeSetting;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;

class NfeTestSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'nfe-teste@example.com'],
            [
                'name' => 'Empresa Teste NF-e',
                'email_verified_at' => now(),
                'password' => 'password',
                'role' => 'enterprise',
            ],
        );

        FocusNfeSetting::firstOrCreate(
            ['user_id' => $user->id],
            ['settings' => FocusNfeSetting::factory()->make()->settings],
        );

        $client = Client::updateOrCreate(
            ['user_id' => $user->id, 'email' => 'cliente-nfe@example.com'],
            Client::factory()->make([
                'user_id' => $user->id,
                'email' => 'cliente-nfe@example.com',
            ])->toArray(),
        );

        $product = Product::updateOrCreate(
            ['user_id' => $user->id, 'sku' => 'CAMISETA-TESTE'],
            Product::factory()->make([
                'user_id' => $user->id,
                'sku' => 'CAMISETA-TESTE',
            ])->toArray(),
        );

        $sale = Sale::firstOrCreate(
            ['user_id' => $user->id, 'number' => 'TESTE-NFE-001'],
            Sale::factory()->make([
                'user_id' => $user->id,
                'client_id' => $client->id,
                'number' => 'TESTE-NFE-001',
            ])->toArray(),
        );

        if (! $sale->saleItems()->exists()) {
            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_code' => (string) $product->id,
                'unit' => $product->unit,
                'quantity' => 1,
                'unit_price' => $product->sale_price,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => $product->sale_price,
            ]);
        }

        Service::updateOrCreate(
            ['user_id' => $user->id, 'code' => 'SERVICO-TESTE'],
            Service::factory()->make([
                'user_id' => $user->id,
                'client_id' => $client->id,
                'code' => 'SERVICO-TESTE',
            ])->toArray(),
        );
    }
}
