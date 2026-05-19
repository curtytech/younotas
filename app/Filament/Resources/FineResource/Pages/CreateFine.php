<?php

namespace App\Filament\Resources\FineResource\Pages;

use App\Filament\Resources\FineResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateFine extends CreateRecord
{
    protected static string $resource = FineResource::class;

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
