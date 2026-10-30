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

        $data = static::getResource()::normalizeOwnership($data, $this->record);
        foreach (['product_id', 'company_id', 'movement_type', 'source_type', 'quantity', 'previous_stock', 'current_stock'] as $field) {
            $data[$field] = $this->record->$field;
        }
        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
