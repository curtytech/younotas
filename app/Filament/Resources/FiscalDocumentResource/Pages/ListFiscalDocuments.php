<?php

namespace App\Filament\Resources\FiscalDocumentResource\Pages;

use App\Filament\Resources\FiscalDocumentResource;
use Filament\Resources\Pages\ListRecords;

class ListFiscalDocuments extends ListRecords
{
    protected static string $resource = FiscalDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
