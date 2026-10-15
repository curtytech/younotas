<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    use \App\Filament\Resources\Concerns\InteractsWithCompanyOwnership;

    protected static string $resource = ProductResource::class;

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
