<?php

namespace App\Filament\Resources\AppealResource\Pages;

use App\Filament\Resources\AppealResource;
use Filament\Resources\Pages\EditRecord; // <- IMPORT CORRETO
use Filament\Actions;

class EditAppeal extends EditRecord
{
    protected static string $resource = AppealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getPreviousUrl() ?? $this->getResource()::getUrl('index');
    }

    protected function getPreviousUrl(): ?string
    {
        return session()->pull('filament.previous_url');
    }
}
