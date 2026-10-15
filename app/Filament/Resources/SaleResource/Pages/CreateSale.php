<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateSale extends CreateRecord
{
    use \App\Filament\Resources\Concerns\InteractsWithCompanyOwnership;

    protected static string $resource = SaleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['number'] = 'TMP-' . uniqid();


        return static::getResource()::normalizeOwnership($data);
    }

    protected function afterCreate(): void
    {
        $this->record->update([
            'number' => (string) $this->record->id,
        ]);
    }
}
