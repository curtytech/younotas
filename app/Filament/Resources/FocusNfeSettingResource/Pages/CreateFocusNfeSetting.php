<?php

namespace App\Filament\Resources\FocusNfeSettingResource\Pages;

use App\Filament\Resources\FocusNfeSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFocusNfeSetting extends CreateRecord
{
    protected static string $resource = FocusNfeSettingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (auth()->user()->role !== 'admin') {
            $data['user_id'] = auth()->id();
        }

        return $data;
    }
}
