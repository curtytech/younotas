<?php

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\FocusNfeSettingResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\SaleResource;
use App\Filament\Resources\ServiceOrderResource;
use App\Filament\Resources\ServiceResource;
use App\Filament\Resources\StockMovementResource;
use App\Filament\Resources\TechnicianResource;
use App\Models\Appeal;
use App\Models\Client;
use App\Models\Driver;
use App\Models\Fine;
use App\Models\FocusNfeSetting;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\StockMovement;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed();
    $this->user = User::where('email', 'test@example.com')->firstOrFail();
    $this->actingAs($this->user);
});

test('seed disponibiliza dados nas listas comerciais, fiscais e operacionais', function (): void {
    expect(ClientResource::getEloquentQuery()->count())->toBeGreaterThan(0)
        ->and(ProductResource::getEloquentQuery()->count())->toBeGreaterThan(0)
        ->and(ServiceResource::getEloquentQuery()->count())->toBeGreaterThan(0)
        ->and(SaleResource::getEloquentQuery()->count())->toBeGreaterThan(0)
        ->and(StockMovementResource::getEloquentQuery()->count())->toBeGreaterThan(0)
        ->and(TechnicianResource::getEloquentQuery()->count())->toBeGreaterThan(0)
        ->and(ServiceOrderResource::getEloquentQuery()->count())->toBeGreaterThan(0)
        ->and(FocusNfeSettingResource::getEloquentQuery()->count())->toBeGreaterThan(0);
});

test('seed disponibiliza dados nas entidades de frota, multas e auditoria', function (): void {
    expect(Vehicle::where('user_id', $this->user->id)->count())->toBeGreaterThan(0)
        ->and(Driver::where('user_id', $this->user->id)->count())->toBeGreaterThan(0)
        ->and(Fine::where('user_id', $this->user->id)->count())->toBeGreaterThan(0)
        ->and(Appeal::where('user_id', $this->user->id)->count())->toBeGreaterThan(0)
        ->and(FocusNfeSetting::where('user_id', $this->user->id)->count())->toBeGreaterThan(0)
        ->and(Client::where('user_id', $this->user->id)->count())->toBeGreaterThan(0)
        ->and(Product::where('user_id', $this->user->id)->count())->toBeGreaterThan(0)
        ->and(Service::where('user_id', $this->user->id)->count())->toBeGreaterThan(0)
        ->and(Sale::where('user_id', $this->user->id)->count())->toBeGreaterThan(0)
        ->and(StockMovement::where('user_id', $this->user->id)->count())->toBeGreaterThan(0)
        ->and(Technician::where('user_id', $this->user->id)->count())->toBeGreaterThan(0)
        ->and(ServiceOrder::where('user_id', $this->user->id)->count())->toBeGreaterThan(0);
});

test('recalcula os totais da ordem de serviço ao salvar um item', function (): void {
    $order = ServiceOrder::factory()->create([
        'user_id' => $this->user->id,
        'discount_amount' => 5,
    ]);

    $order->items()->create([
        'service_name' => 'Instalação',
        'quantity' => 2,
        'unit_price' => 100,
        'discount_amount' => 10,
        'tax_amount' => 5,
    ]);

    $order->refresh();

    expect((float) $order->subtotal_amount)->toBe(190.0)
        ->and((float) $order->tax_amount)->toBe(5.0)
        ->and((float) $order->total_amount)->toBe(190.0);
});

test('permite apenas transições válidas de status da ordem de serviço', function (): void {
    $order = ServiceOrder::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'scheduled',
    ]);

    $order->update(['status' => 'in_progress']);

    expect($order->fresh()->status)->toBe('in_progress');

    expect(fn () => $order->update(['status' => 'billed']))
        ->toThrow(InvalidArgumentException::class);
});
