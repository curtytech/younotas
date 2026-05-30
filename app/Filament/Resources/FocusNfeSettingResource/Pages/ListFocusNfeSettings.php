<?php

namespace App\Filament\Resources\FocusNfeSettingResource\Pages;

use App\Filament\Resources\FocusNfeSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFocusNfeSettings extends ListRecords
{
    protected static string $resource = FocusNfeSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
