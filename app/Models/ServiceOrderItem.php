<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_order_id', 'service_id', 'service_name', 'service_code', 'description',
        'municipal_service_code', 'lc116_code', 'cnae_code', 'nbs_code', 'unit', 'quantity',
        'unit_price', 'discount_amount', 'iss_aliquot', 'pis_aliquot', 'cofins_aliquot',
        'inss_aliquot', 'ir_aliquot', 'csll_aliquot', 'tax_amount', 'total_amount',
    ];

    protected $casts = [
        'quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'discount_amount' => 'decimal:2',
        'iss_aliquot' => 'decimal:2', 'pis_aliquot' => 'decimal:2', 'cofins_aliquot' => 'decimal:2',
        'inss_aliquot' => 'decimal:2', 'ir_aliquot' => 'decimal:2', 'csll_aliquot' => 'decimal:2',
        'tax_amount' => 'decimal:2', 'total_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saved(fn (ServiceOrderItem $item) => $item->order?->recalculateTotals());
        static::deleted(fn (ServiceOrderItem $item) => $item->order?->recalculateTotals());
    }

    public function order(): BelongsTo { return $this->belongsTo(ServiceOrder::class, 'service_order_id'); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
}
