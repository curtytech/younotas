<?php

namespace App\Models;

use App\Services\FocusNfeConfigService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class FiscalConnection extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'provider', 'environment', 'api_key', 'enabled', 'settings', 'validated_at'];

    protected $hidden = ['api_key', 'settings'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'settings' => 'encrypted:array', 'enabled' => 'boolean', 'validated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (FiscalConnection $connection): void {
            if (! isset(FocusNfeConfigService::ENVIRONMENT_URLS[$connection->environment])) {
                throw new RuntimeException('Ambiente da conexão fiscal inválido.');
            }

            if ($connection->exists && $connection->isDirty(['company_id', 'provider', 'environment'])) {
                throw new RuntimeException('A empresa, o provedor e o ambiente de uma conexão fiscal são imutáveis.');
            }

            if ($connection->isDirty('settings')) {
                $connection->settings = FocusNfeConfigService::sanitizeSnapshot($connection->settings ?? []);
            }

            if ($connection->isDirty('api_key')) {
                $connection->validated_at = null;
            }
        });

        static::deleting(function (FiscalConnection $connection): void {
            if (FiscalDocument::query()->where('fiscal_connection_id', $connection->id)->exists()
                || Sale::query()->where('focus_nfe_connection_id', $connection->id)->exists()
                || ServiceOrder::query()->where('focus_nfse_connection_id', $connection->id)->exists()) {
                throw new RuntimeException('Esta conexão possui documentos fiscais. Desative-a em vez de excluir.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
