<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\FocusNfeSetting;
use App\Models\User;
use App\Services\FocusNfeConfigService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::saving(function (Model $model): void {
            if ($model->exists && filled($model->getOriginal('company_id')) && $model->isDirty('company_id')) {
                throw ValidationException::withMessages(['company_id' => 'Não é permitido transferir um registro existente para outra empresa.']);
            }

            if (blank($model->company_id) && filled($model->user_id)) {
                $user = User::query()->find($model->user_id);

                if ($user) {
                    $model->company_id = app(FocusNfeConfigService::class)->ensureCompanyForUser($user)->id;

                    if ($model instanceof FocusNfeSetting) {
                        $model->legacyOwnerConfiguration = true;
                    }
                }
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeVisibleTo(Builder $query, User $user, ?string $permission = null): Builder
    {
        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->role === 'admin') {
            return $query;
        }

        $roles = User::companyRolesForPermission($permission);

        return $query->whereIn($query->getModel()->qualifyColumn('company_id'), $user->companies()
            ->wherePivotIn('role', $roles)->select('companies.id'));
    }
}
