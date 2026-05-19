<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use Filament\Resources\Pages\EditRecord;

class EditDriver extends EditRecord
{
    protected static string $resource = DriverResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getReturnUrl() ?? $this->getResource()::getUrl('index');
    }

    protected function getReturnUrl(): ?string
    {
        return request()->query('return_url');
    }
}
