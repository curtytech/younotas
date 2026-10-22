<?php

namespace App\Filament\Resources\FocusNfeSettingResource\Pages;

use App\Filament\Resources\FocusNfeSettingResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateFocusNfeSetting extends CreateRecord
{
    protected static string $resource = FocusNfeSettingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = FocusNfeSettingResource::normalizeOwnership($data, null, 'manage_fiscal');
        if (($data['settings']['environment'] ?? 'homologacao') === 'producao' && ! auth()->user()->canAccessCompany($data['company_id'], 'production')) {
            throw ValidationException::withMessages(['settings.environment' => 'Você não tem permissão para habilitar produção.']);
        }
        $data['settings']['remove_api_keys'] = $data['remove_api_keys'] ?? [];
        unset($data['remove_api_keys']);
        return $data;
    }
}
