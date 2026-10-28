<?php

namespace App\Filament\Resources\QuoteResource\Pages;

use App\Filament\Resources\Concerns\InteractsWithCompanyOwnership;
use App\Filament\Resources\QuoteResource;
use Filament\Resources\Pages\EditRecord;

class EditQuote extends EditRecord
{
    use InteractsWithCompanyOwnership;

    protected static string $resource = QuoteResource::class;
}
