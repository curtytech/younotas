<?php

namespace App\Filament\Resources\ServiceOrderResource\Pages;

use App\Filament\Resources\Concerns\InteractsWithCompanyOwnership;
use App\Filament\Resources\ServiceOrderResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateServiceOrder extends CreateRecord
{
    use InteractsWithCompanyOwnership;

    protected static string $resource = ServiceOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = ServiceOrderResource::normalizeOwnership($data);
        $data['number'] = 'OS-'.Str::ulid();
        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->update(['number' => 'OS-'.str_pad((string) $this->record->id, 6, '0', STR_PAD_LEFT)]);
    }
}
