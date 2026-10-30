<?php

use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dados fiscais enviados no item são derivados do serviço e preservados na edição', function () {
    $order = ServiceOrder::factory()->create();
    $service = Service::factory()->create(['company_id' => $order->company_id, 'lc116_code' => '0107']);
    $item = ServiceOrderItem::create([
        'service_order_id' => $order->id, 'service_id' => $service->id,
        'service_name' => 'Adulterado', 'lc116_code' => '9999',
        'quantity' => 1, 'unit_price' => 100,
    ]);
    expect($item->fresh()->lc116_code)->toBe('0107')
        ->and($item->fresh()->service_name)->toBe($service->name);
    $item->update(['lc116_code' => '9999']);
    expect($item->fresh()->lc116_code)->toBe('0107');
});
