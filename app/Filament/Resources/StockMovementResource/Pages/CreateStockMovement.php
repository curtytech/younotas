<?php

namespace App\Filament\Resources\StockMovementResource\Pages;

use App\Filament\Resources\StockMovementResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateStockMovement extends CreateRecord
{
    use \App\Filament\Resources\Concerns\InteractsWithCompanyOwnership;

    protected static string $resource = StockMovementResource::class;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $product = \App\Models\Product::query()->lockForUpdate()->findOrFail($data['product_id']);
            abort_unless(auth()->user()->canAccessCompany((int) $product->company_id, 'issue'), 403);
            $previous = (float) $product->stock_quantity;
            $quantity = (float) $data['quantity'];
            $current = match ($data['movement_type']) {
                'entry' => $previous + $quantity,
                'exit' => $previous - $quantity,
                'adjustment' => $quantity,
            };
            if ($current < 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['data.quantity' => 'A quantidade de saída supera o saldo disponível.']);
            }
            $product->update(['stock_quantity' => $current]);
            return \App\Models\StockMovement::create(array_replace($data, [
                'company_id' => $product->company_id, 'source_type' => 'manual',
                'previous_stock' => $previous, 'current_stock' => $current,
            ]));
        });
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {

        return static::getResource()::normalizeOwnership($data);
    }
}
