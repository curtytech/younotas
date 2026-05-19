<?php

namespace App\Filament\Resources\FineResource\Pages;

use App\Filament\Resources\FineResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions;

class EditFine extends EditRecord
{
    protected static string $resource = FineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        // Volta para a URL anterior, se houver, senão vai para a lista
        return $this->getPreviousUrl() ?? $this->getResource()::getUrl('index');
    }

    protected function getPreviousUrl(): ?string
    {
        return session()->pull('filament.previous_url');
    }
}
