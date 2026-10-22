<?php
namespace App\Filament\Resources\TechnicianResource\Pages;
use App\Filament\Resources\TechnicianResource;
use Filament\Resources\Pages\EditRecord;
class EditTechnician extends EditRecord {
    use \App\Filament\Resources\Concerns\InteractsWithCompanyOwnership;
 protected static string $resource = TechnicianResource::class; }
