<?php

namespace App\Filament\Resources\FocusNfeSettingResource\Pages;

use App\Filament\Resources\FocusNfeSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFocusNfeSetting extends EditRecord
{
    protected static string $resource = FocusNfeSettingResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (auth()->user()->role !== 'admin') {
            $data['user_id'] = auth()->id();
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (): bool => auth()->user()->role === 'admin'),
        ];
    }
}
