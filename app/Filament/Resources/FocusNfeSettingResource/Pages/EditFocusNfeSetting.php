<?php

namespace App\Filament\Resources\FocusNfeSettingResource\Pages;

use App\Filament\Resources\FocusNfeSettingResource;
use App\Services\FocusNfeConfigService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditFocusNfeSetting extends EditRecord
{
    protected static string $resource = FocusNfeSettingResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['settings'] = FocusNfeConfigService::normalizeSettings($data['settings'] ?? []);
        unset($data['settings']['api_key'], $data['settings']['api_keys'], $data['settings']['api_password']);
        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = FocusNfeSettingResource::normalizeOwnership($data, $this->record, 'manage_fiscal');
        $environment = $data['settings']['environment'] ?? 'homologacao';
        if ($environment === 'producao' && ! auth()->user()->canAccessCompany($data['company_id'], 'production')) {
            throw ValidationException::withMessages(['settings.environment' => 'Você não tem permissão para habilitar produção.']);
        }
        if (! ($data['remove_api_keys'][$environment] ?? false) && ! filled($data['settings']['api_keys'][$environment] ?? null) && ! FocusNfeSettingResource::hasConfiguredToken($this->record, $environment)) {
            throw ValidationException::withMessages(['settings.api_keys.'.$environment => 'Cadastre a credencial do ambiente padrão.']);
        }
        $data['settings']['remove_api_keys'] = $data['remove_api_keys'] ?? [];
        unset($data['remove_api_keys']);
        return $data;
    }
}
