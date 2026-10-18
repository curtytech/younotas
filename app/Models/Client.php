<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Client extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Client $client): void {
            if ($client->is_active && $client->hasProvisionalData()) {
                throw ValidationException::withMessages([
                    'is_active' => 'Corrija os dados provisorios antes de ativar o cliente.',
                ]);
            }
        });
    }

    public function hasProvisionalData(): bool
    {
        return str_ends_with(strtolower((string) $this->email), '@cadastro.invalid')
            || $this->phone === 'Nao informado'
            || str_starts_with((string) $this->address, 'Endereco provisorio ');
    }

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'document_type',
        'document',
        'address',
        'address_number',
        'address_complement',
        'neighborhood',
        'city',
        'ibge_code',
        'state',
        'zip_code',
        'country',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
