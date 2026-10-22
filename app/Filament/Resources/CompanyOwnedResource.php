<?php

namespace App\Filament\Resources;

use App\Models\Company;
use App\Models\User;
use App\Services\FocusNfeConfigService;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

abstract class CompanyOwnedResource extends Resource
{
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function companyOptions(string $permission = 'issue'): array
    {
        $user = auth()->user();
        if (! $user || ! $user->is_active) {
            return [];
        }
        if (! $user->companies()->exists()) {
            app(FocusNfeConfigService::class)->ensureCompanyForUser($user);
        }
        return Company::query()->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (Company $company): bool => $user->canAccessCompany($company, $permission))
            ->mapWithKeys(fn (Company $company): array => [$company->id => $company->name.($company->cnpj ? ' · '.$company->cnpj : '')])->all();
    }

    public static function companyField(string $permission = 'issue'): Select
    {
        return Select::make('company_id')->label('Empresa emitente')->options(fn (): array => static::companyOptions($permission))
            ->default(fn (): int => app(FocusNfeConfigService::class)->ensureCompanyForUser(auth()->user())->id)
            ->required()->searchable()->preload()->native(false)->live()
            ->disabled(fn (?Model $record): bool => (bool) $record?->exists)->dehydrated();
    }

    public static function normalizeOwnership(array $data, ?Model $record = null, string $permission = 'issue'): array
    {
        $user = auth()->user();
        $companyId = (int) ($record?->company_id ?? $data['company_id'] ?? app(FocusNfeConfigService::class)->ensureCompanyForUser($user)->id);
        if (! $user->canAccessCompany($companyId, $permission)) {
            throw ValidationException::withMessages(['company_id' => 'Você não tem permissão para alterar dados desta empresa.']);
        }
        if ($record?->exists && isset($data['company_id']) && (int) $data['company_id'] !== $companyId) {
            throw ValidationException::withMessages(['company_id' => 'A empresa de um registro existente não pode ser alterada.']);
        }
        $data['company_id'] = $companyId;
        $data['user_id'] = $record?->user_id ?? $user->id;
        return $data;
    }

    public static function canManageCompanyRecord(Model $record, string $permission = 'issue'): bool
    {
        return auth()->user()?->canAccessCompany((int) $record->company_id, $permission) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canManageCompanyRecord($record, 'view');
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();
        if (! $user?->is_active) {
            return false;
        }
        if ($user->role === 'admin') {
            return true;
        }
        if (! $user->companies()->exists()) {
            app(FocusNfeConfigService::class)->ensureCompanyForUser($user);
        }
        return static::companyOptions() !== [];
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManageCompanyRecord($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManageCompanyRecord($record);
    }
}
