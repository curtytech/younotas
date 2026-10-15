<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'user_id', 'company_id',
        'name',
        'email',
        'phone',
        'document_type',
        'document',
        'inscricao_estatual',
        'indicador_ie',
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
