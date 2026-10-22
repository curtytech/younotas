<?php

namespace App\Models;

use App\Services\FiscalDocumentRecorder;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\NormalizesOptionalNumbers;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            if ($sale->client_id && (int) $sale->client()->value('company_id') !== (int) $sale->company_id) {
                throw \Illuminate\Validation\ValidationException::withMessages(['client_id' => 'O cliente deve pertencer à mesma empresa da venda.']);
            }
        });
        static::deleting(function (Sale $sale): void {
            if ($sale->fiscalDocuments()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['sale' => 'Preserve a venda vinculada ao histórico fiscal.']);
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
