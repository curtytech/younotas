<?php

namespace App\Filament\Resources\Concerns;

trait InteractsWithCompanyOwnership
{
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return static::getResource()::normalizeOwnership($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return static::getResource()::normalizeOwnership($data, $this->record);
    }
}
