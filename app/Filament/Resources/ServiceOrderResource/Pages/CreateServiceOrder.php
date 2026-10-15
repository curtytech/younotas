<?php
namespace App\Filament\Resources\ServiceOrderResource\Pages;
use App\Filament\Resources\ServiceOrderResource;
use App\Models\ServiceOrder;
use Filament\Resources\Pages\CreateRecord;
class CreateServiceOrder extends CreateRecord { protected static string $resource = ServiceOrderResource::class; protected function mutateFormDataBeforeCreate(array $data): array { $data['number'] = 'OS-'.str_pad((string) (ServiceOrder::where('user_id', $data['user_id'] ?? auth()->id())->lockForUpdate()->count() + 1), 6, '0', STR_PAD_LEFT); return $data; } }
