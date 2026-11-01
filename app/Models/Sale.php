<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\NormalizesOptionalNumbers;
use App\Services\FiscalDocumentRecorder;
use App\Services\FiscalIssueDiagnosis;
use App\Support\NfeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Sale extends Model
{
    use HasFactory, NormalizesOptionalNumbers, BelongsToCompany;

    protected array $optionalNumbers = [
        'discount_amount',
        'tax_amount',
    ];

    protected static function booted(): void
    {
        static::saving(function (Sale $sale): void {
            if ($sale->exists) {
                $sale->subtotal_amount = $sale->calculatedSubtotal();
                $sale->total_amount = max(round((float) $sale->subtotal_amount - (float) $sale->discount_amount + (float) $sale->tax_amount, 2), 0);
            }

            if ($sale->client_id && (int) $sale->client()->value('company_id') !== (int) $sale->company_id) {
                throw ValidationException::withMessages(['client_id' => 'O cliente deve pertencer à mesma empresa da venda.']);
            }
        });
        static::deleting(function (Sale $sale): void {
            if ($sale->fiscalDocuments()->exists()) {
                throw ValidationException::withMessages(['sale' => 'Preserve a venda vinculada ao histórico fiscal.']);
            }
            $sale->saleItems()->get()->each->delete();
        });

        static::saved(function (Sale $sale): void {
            $tracked = ['focus_nfe_ref', 'focus_nfe_status', 'focus_nfe_number', 'focus_nfe_url', 'focus_nfe_last_checked_at', 'focus_nfe_last_webhook_at'];

            if (filled($sale->focus_nfe_ref) && ($sale->wasRecentlyCreated || $sale->wasChanged($tracked))) {
                app(FiscalDocumentRecorder::class)->recordSale($sale);
            }
        });
    }

    protected $fillable = [
        'user_id',
        'company_id',
        'client_id',
        'number',
        'sale_date',
        'status',
        'payment_status',
        'issue_invoice',
        'focus_nfe_ref',
        'focus_nfe_connection_id',
        'focus_nfe_environment',
        'focus_nfe_configuration_snapshot',
        'focus_nfe_next_check_at',
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
        'focus_nfe_configuration_snapshot' => 'encrypted:array',
        'focus_nfe_next_check_at' => 'datetime',
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

    public function calculatedSubtotal(): float
    {
        return round($this->saleItems()->get()->sum(fn (SaleItem $item): float => max(round(
            (float) $item->quantity * (float) $item->unit_price
            - (float) $item->discount_amount + (float) $item->tax_amount,
            2,
        ), 0)), 2);
    }

    /** Sent or authorized notes keep the data they were issued with. */
    public function hasLockedNfe(): bool
    {
        return in_array($this->focus_nfe_status, [NfeStatus::SENDING, NfeStatus::PROCESSING, NfeStatus::AUTHORIZED], true);
    }

    /** @return array{category: string, summary: string, details: list<string>, action: string} */
    public function nfeDiagnosis(): array
    {
        $document = (new FiscalDocument)->forceFill([
            'status' => $this->focus_nfe_status,
            'fiscal_connection_id' => $this->focus_nfe_connection_id,
            'environment' => $this->focus_nfe_environment,
            'last_error' => $this->focus_nfe_error,
            'raw_response' => $this->focus_nfe_response_secure,
            'last_sent_at' => $this->focus_nfe_last_sent_at,
            'last_checked_at' => $this->focus_nfe_last_checked_at,
            'updated_at' => $this->updated_at,
        ]);

        return app(FiscalIssueDiagnosis::class)->explain($document);
    }

    public function recalculateTotals(): void
    {
        $this->subtotal_amount = $this->calculatedSubtotal();
        $this->total_amount = max(round((float) $this->subtotal_amount - (float) $this->discount_amount + (float) $this->tax_amount, 2), 0);
        $this->saveQuietly();
    }

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

    public function fiscalConnection(): BelongsTo
    {
        return $this->belongsTo(FiscalConnection::class, 'focus_nfe_connection_id');
    }

    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class, 'source_id')->where('source_type', self::class);
    }
}
