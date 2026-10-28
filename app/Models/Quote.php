<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Quote extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'user_id', 'company_id', 'client_id', 'number', 'quote_date',
        'valid_until', 'status', 'discount_amount', 'total_amount', 'notes',
    ];

    protected $casts = [
        'quote_date' => 'date',
        'valid_until' => 'date',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (Quote $quote): void {
            if ($quote->client_id && (int) $quote->client()->value('company_id') !== (int) $quote->company_id) {
                throw ValidationException::withMessages([
                    'client_id' => 'O cliente deve pertencer à mesma empresa do orçamento.',
                ]);
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    public function recalculateTotal(): void
    {
        $this->forceFill([
            'total_amount' => max((float) $this->items()->sum('total_amount') - (float) $this->discount_amount, 0),
        ])->saveQuietly();
    }
}
