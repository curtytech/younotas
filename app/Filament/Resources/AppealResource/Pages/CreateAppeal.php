<?php

namespace App\Filament\Resources\AppealResource\Pages;

use App\Filament\Resources\AppealResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAppeal extends CreateRecord
{
    protected static string $resource = AppealResource::class;

    protected function getRedirectUrl(): string
    {
        // Volta para a tela anterior ou, se não houver, para o index do Resource
        return $this->getPreviousUrl() ?? $this->getResource()::getUrl('index');
    }

    protected function getPreviousUrl(): ?string
    {
        // Pega a URL anterior da sessão
        return session()->pull('filament.previous_url');
    }
}
