<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use RuntimeException;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'primary_company_id',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function getIsActiveAttribute(mixed $value): bool
    {
        return $value === null ? true : (bool) $value;
    }

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            if ($user->focusNfeSetting()->exists()
                || FiscalDocument::query()->where('user_id', $user->id)->orWhere('issued_by', $user->id)->exists()
                || $user->sales()->exists()
                || $user->serviceOrders()->exists()
                || $user->companies()->exists()) {
                throw new RuntimeException('Este usuário possui vínculos empresariais ou histórico fiscal. Desative o acesso para preservar os dados.');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && in_array($this->role, ['admin', 'enterprise'], true);
    }

    public function primaryCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'primary_company_id');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withPivot('role')->withTimestamps();
    }

    public static function companyRolesForPermission(?string $permission = null): array
    {
        return match ($permission) {
            null, 'view' => ['owner', 'manager', 'operator', 'viewer'],
            'issue' => ['owner', 'manager', 'operator'],
            'manage_fiscal', 'manage_members', 'production' => ['owner', 'manager'],
            'owner' => ['owner'],
            default => [],
        };
    }

    public function canAccessCompany(Company|int $company, ?string $permission = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $companyId = $company instanceof Company ? $company->id : $company;

        if ($this->role === 'admin') {
            return true;
        }

        return $this->companies()->whereKey($companyId)
            ->wherePivotIn('role', self::companyRolesForPermission($permission))->exists();
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class);
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(Appeal::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function technicians(): HasMany
    {
        return $this->hasMany(Technician::class);
    }

    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function focusNfeSetting(): HasOne
    {
        return $this->hasOne(FocusNfeSetting::class);
    }
}
