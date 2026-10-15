<?php
namespace App\Filament\Resources\CompanyResource\Pages;
use App\Filament\Resources\CompanyResource;
use Filament\Resources\Pages\CreateRecord;
class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;
    protected function afterCreate(): void
    {
        $this->record->members()->attach(auth()->id(), ['role' => 'owner']);
    }
}
