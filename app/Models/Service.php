<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'client_id',
        'code',
        'name',
        'description',
        'municipal_service_code',
        'lc116_code',
        'cnae_code',
        'nbs_code',
        'unit',
        'unit_price',
        'iss_aliquot',
        'pis_aliquot',
        'cofins_aliquot',
        'inss_aliquot',
        'ir_aliquot',
        'csll_aliquot',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'iss_aliquot' => 'decimal:2',
        'pis_aliquot' => 'decimal:2',
        'cofins_aliquot' => 'decimal:2',
        'inss_aliquot' => 'decimal:2',
        'ir_aliquot' => 'decimal:2',
        'csll_aliquot' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
