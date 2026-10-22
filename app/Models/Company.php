<?php

namespace App\Models;

use App\Services\FocusNfeConfigService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class Company extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'cnpj', 'default_environment', 'is_active', 'fiscal_settings'];

    protected $hidden = ['fiscal_settings'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'fiscal_settings' => 'encrypted:array'];
    }

    protected static function booted(): void
    {
        static::saving(function (Company $company): void {
            if ($company->exists && $company->isDirty('cnpj') && filled($company->getOriginal('cnpj'))
                && strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string) $company->cnpj)) !== strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string) $company->getOriginal('cnpj')))
                && $company->fiscalDocuments()->exists()) {
                throw ValidationException::withMessages([
                    'cnpj' => 'Esta empresa possui documentos fiscais. Cadastre outro emitente para utilizar um CNPJ diferente.',
                    'settings.prestador.cnpj' => 'Esta empresa possui documentos fiscais. Cadastre outro emitente para utilizar um CNPJ diferente.',
                ]);
            }

            if (! isset(FocusNfeConfigService::ENVIRONMENT_URLS[$company->default_environment ?? 'homologacao'])) {
                throw new RuntimeException('Ambiente fiscal inválido.');
            }

            if ($company->isDirty('fiscal_settings')) {
                $company->fiscal_settings = FocusNfeConfigService::sanitizeSnapshot($company->fiscal_settings ?? []);
            }

            $settings = $company->fiscal_settings ?? [];

            if ($company->isDirty('name') && data_get($settings, 'nfe.emitente') !== null) {
                data_set($settings, 'nfe.emitente.nome', $company->name);
            }

            if ($company->isDirty('cnpj') && data_get($settings, 'prestador') !== null) {
                data_set($settings, 'prestador.cnpj', $company->cnpj);
            }

            $company->fiscal_settings = $settings;
        });

        static::deleting(function (Company $company): void {
            if ($company->fiscalDocuments()->exists()) {
                throw new RuntimeException('Esta empresa possui documentos fiscais. Desative o cadastro para preservar o histórico.');
            }
        });

        static::saved(function (Company $company): void {
            if (! $company->wasRecentlyCreated && $company->wasChanged('default_environment')) {
                FiscalEvent::create([
                    'company_id' => $company->id,
                    'actor_id' => auth()->id(),
                    'event' => 'default_environment_changed',
                    'context' => [
                        'from' => $company->getOriginal('default_environment'),
                        'to' => $company->default_environment,
                    ],
                ]);
            }

            // Keep the transitional form projection synchronized with the canonical issuer.
            DB::table('focus_nfe_settings')->where('company_id', $company->id)->update([
                'settings' => Crypt::encryptString(json_encode(array_replace($company->fiscal_settings ?? [], [
                    'environment' => $company->default_environment,
                ]), JSON_THROW_ON_ERROR)),
            ]);
        });
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function connections(): HasMany
    {
        return $this->hasMany(FiscalConnection::class);
    }

    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }

    public function canAccess(User $user, ?string $permission = null): bool
    {
        return $user->canAccessCompany($this, $permission);
    }
}
