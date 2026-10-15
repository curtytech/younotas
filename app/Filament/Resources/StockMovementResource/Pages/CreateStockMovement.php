<?php

namespace App\Filament\Resources\StockMovementResource\Pages;

use App\Filament\Resources\StockMovementResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateStockMovement extends CreateRecord
{
    use \App\Filament\Resources\Concerns\InteractsWithCompanyOwnership;

    protected static string $resource = StockMovementResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {

        return static::getResource()::normalizeOwnership($data);
    }
}
