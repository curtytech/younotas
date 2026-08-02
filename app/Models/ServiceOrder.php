<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class ServiceOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'client_id', 'technician_id', 'number', 'status', 'scheduled_for',
        'started_at', 'completed_at', 'problem_description', 'execution_description',
        'subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount', 'notes',
        'signature_path', 'signed_by_name', 'signed_at', 'focus_nfse_ref', 'focus_nfse_status',
        'focus_nfse_number', 'focus_nfse_url', 'focus_nfse_response_secure', 'focus_nfse_payload',
        'focus_nfse_error', 'focus_nfse_attempts', 'focus_nfse_last_sent_at',
        'focus_nfse_last_checked_at', 'focus_nfse_last_webhook_at',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
        'signed_at' => 'datetime', 'focus_nfse_response_secure' => 'encrypted:array',
        'focus_nfse_payload' => 'encrypted:array', 'focus_nfse_error' => 'encrypted:array',
        'focus_nfse_attempts' => 'integer', 'focus_nfse_last_sent_at' => 'datetime',
        'focus_nfse_last_checked_at' => 'datetime', 'focus_nfse_last_webhook_at' => 'datetime',
        'subtotal_amount' => 'decimal:2', 'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2', 'total_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (ServiceOrder $order): void {
            if (! $order->exists || ! $order->isDirty('status')) {
                return;
            }

            $allowed = [
                'draft' => ['scheduled', 'canceled'],
                'scheduled' => ['draft', 'in_progress', 'canceled'],
                'in_progress' => ['completed', 'canceled'],
                'completed' => ['billed', 'canceled'],
                'billed' => ['completed'],
                'canceled' => [],
            ];

            if (! in_array($order->status, $allowed[$order->getOriginal('status')] ?? [], true)) {
                throw new InvalidArgumentException('Transição de status de O.S. não permitida.');
            }
        });
    }

    public function recalculateTotals(): void
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        $subtotal = $items->sum(fn (ServiceOrderItem $item): float => (float) $item->quantity * (float) $item->unit_price - (float) $item->discount_amount);
        $tax = $items->sum(fn (ServiceOrderItem $item): float => (float) $item->tax_amount);
        $this->forceFill([
            'subtotal_amount' => max(0, $subtotal),
            'tax_amount' => max(0, $tax),
            'total_amount' => max(0, $subtotal + $tax - (float) $this->discount_amount),
        ])->saveQuietly();
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function technician(): BelongsTo { return $this->belongsTo(Technician::class); }
    public function items(): HasMany { return $this->hasMany(ServiceOrderItem::class); }
    public function attachments(): HasMany { return $this->hasMany(ServiceOrderAttachment::class); }
}
