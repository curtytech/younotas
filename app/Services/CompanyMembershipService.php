<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use App\Models\FiscalEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyMembershipService
{
    public function assign(Company $company, User $member, string $role, User $actor): void
    {
        DB::transaction(function () use ($company, $member, $role, $actor): void {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            abort_unless($actor->canAccessCompany($company, 'manage_members'), 403);
            if (! in_array($role, ['owner', 'manager', 'operator', 'viewer'], true)) {
                throw ValidationException::withMessages(['role' => 'Permissão inválida.']);
            }
            $previous = $company->members()->whereKey($member->id)->first()?->pivot->role;
            if (($role === 'owner' || $previous === 'owner') && ! $actor->canAccessCompany($company, 'owner')) {
                throw ValidationException::withMessages(['role' => 'Somente um proprietário pode alterar proprietários.']);
            }
            if ($previous === 'owner' && $role !== 'owner' && $company->members()->wherePivot('role', 'owner')->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'Mantenha ao menos um proprietário na empresa.']);
            }
            $company->members()->syncWithoutDetaching([$member->id => ['role' => $role]]);
            FiscalEvent::create(['company_id' => $company->id, 'actor_id' => $actor->id, 'event' => 'member_role_changed', 'context' => ['member_id' => $member->id, 'role' => $role]]);
        });
    }

    public function remove(Company $company, User $member, User $actor): void
    {
        DB::transaction(function () use ($company, $member, $actor): void {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            abort_unless($actor->canAccessCompany($company, 'manage_members'), 403);
            $role = $company->members()->whereKey($member->id)->first()?->pivot->role;
            if ($role === 'owner' && (! $actor->canAccessCompany($company, 'owner') || $company->members()->wherePivot('role', 'owner')->count() <= 1)) {
                throw ValidationException::withMessages(['role' => 'Não é possível remover o último proprietário.']);
            }
            $company->members()->detach($member->id);
            if ((int) $member->fresh()->primary_company_id === (int) $company->id) {
                $member->forceFill(['primary_company_id' => $member->companies()->value('companies.id')])->save();
            }
            FiscalEvent::create(['company_id' => $company->id, 'actor_id' => $actor->id, 'event' => 'member_removed', 'context' => ['member_id' => $member->id]]);
        });
    }
}
