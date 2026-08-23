<?php

namespace App\Filament\Resources\FocusNfeSettingResource\Pages;

use App\Filament\Resources\FocusNfeSettingResource;
use App\Models\FocusNfeSetting;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFocusNfeSettings extends ListRecords
{
    protected static string $resource = FocusNfeSettingResource::class;

    public function mount(): void
    {
        if (auth()->user()->role !== 'admin') {
            $setting = FocusNfeSetting::query()
                ->where('user_id', auth()->id())
                ->first();

            $this->redirect(
                $setting
                    ? FocusNfeSettingResource::getUrl('edit', ['record' => $setting])
                    : FocusNfeSettingResource::getUrl('create'),
            );

            return;
        }

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
