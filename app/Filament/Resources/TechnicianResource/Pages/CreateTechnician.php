<?php
namespace App\Filament\Resources\TechnicianResource\Pages;
use App\Filament\Resources\TechnicianResource;
use Filament\Resources\Pages\CreateRecord;
class CreateTechnician extends CreateRecord {
    use \App\Filament\Resources\Concerns\InteractsWithCompanyOwnership;
 protected static string $resource = TechnicianResource::class; }
