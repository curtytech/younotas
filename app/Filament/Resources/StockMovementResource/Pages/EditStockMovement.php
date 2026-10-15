<?php

namespace App\Filament\Resources\StockMovementResource\Pages;

use App\Filament\Resources\StockMovementResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStockMovement extends EditRecord
{
    use \App\Filament\Resources\Concerns\InteractsWithCompanyOwnership;

    protected static string $resource = StockMovementResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {

        return static::getResource()::normalizeOwnership($data, $this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
