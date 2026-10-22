<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\FocusNfeConfigService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FocusNfeSetting extends Model
{
    use BelongsToCompany, HasFactory;

    public bool $legacyOwnerConfiguration = false;

    protected $fillable = [
        'user_id',
        'company_id',
        'settings',
    ];

    protected static function booted(): void
    {
        static::saving(function (FocusNfeSetting $setting): void {
            if ($setting->exists && $setting->isDirty(['company_id', 'user_id'])) {
                throw new RuntimeException('A empresa e o proprietário original de uma configuração fiscal são imutáveis.');
            }

            app(FocusNfeConfigService::class)->synchronizeLegacySetting($setting);
        });
    }

    public function save(array $options = []): bool
    {
        return DB::transaction(fn (): bool => parent::save($options));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected function casts(): array
    {
        return [
            'settings' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
