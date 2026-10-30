<?php

namespace App\Filament\Resources\QuoteResource\Pages;

use App\Filament\Resources\Concerns\InteractsWithCompanyOwnership;
use App\Filament\Resources\QuoteResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateQuote extends CreateRecord
{
    use InteractsWithCompanyOwnership;

    protected static string $resource = QuoteResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = QuoteResource::normalizeOwnership($data);
        $data['number'] = 'TMP-'.Str::ulid();
        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->forceFill([
            'number' => 'ORC-'.str_pad((string) $this->record->getKey(), 6, '0', STR_PAD_LEFT),
        ])->saveQuietly();
    }
}
