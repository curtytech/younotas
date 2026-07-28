<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (Sale $sale): void {
            $sale->saleItems()->get()->each->delete();
        });
    }

    protected $fillable = [
        'user_id',
        'client_id',
        'number',
        'sale_date',
        'status',
        'payment_status',
        'issue_invoice',
        'focus_nfe_ref',
        'focus_nfe_status',
        'focus_nfe_number',
        'focus_nfe_url',
        'focus_nfe_response_secure',
        'focus_nfe_payload',
        'focus_nfe_error',
        'focus_nfe_attempts',
        'focus_nfe_last_sent_at',
        'focus_nfe_last_checked_at',
        'focus_nfe_last_webhook_at',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'notes',
    ];

    protected $casts = [
        'sale_date' => 'date',
        'issue_invoice' => 'boolean',
        'focus_nfe_response_secure' => 'encrypted:array',
        'focus_nfe_payload' => 'encrypted:array',
        'focus_nfe_error' => 'encrypted:array',
        'focus_nfe_last_sent_at' => 'datetime',
        'focus_nfe_last_checked_at' => 'datetime',
        'focus_nfe_last_webhook_at' => 'datetime',
        'subtotal_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }
}
