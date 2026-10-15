<?php

namespace App\Filament\Resources\ServiceResource\Pages;

use App\Filament\Resources\ServiceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditService extends EditRecord
{
    use \App\Filament\Resources\Concerns\InteractsWithCompanyOwnership;

    protected static string $resource = ServiceResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {

        return static::getResource()::normalizeOwnership($data, $this->record);
    }

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
