<?php
namespace App\Filament\Resources\ServiceOrderResource\Pages;
use App\Filament\Resources\ServiceOrderResource;
use Filament\Resources\Pages\EditRecord;
class EditServiceOrder extends EditRecord {
    use \App\Filament\Resources\Concerns\InteractsWithCompanyOwnership;
 protected static string $resource = ServiceOrderResource::class; }
