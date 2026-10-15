<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToCompany;
use App\Services\FiscalIssueDiagnosis;
use App\Support\NfeStatus;
use Illuminate\Database\Eloquent\Builder;

class FiscalDocument extends Model
{
    use HasFactory, BelongsToCompany;

    protected static function booted(): void
    {
        static::updating(function (FiscalDocument $document): void {
            foreach (['company_id', 'fiscal_connection_id', 'environment', 'focus_reference', 'document_type'] as $field) {
                if ($document->getOriginal($field) !== null && $document->isDirty($field)) {
                    throw new \RuntimeException('A identidade de um documento fiscal existente não pode ser alterada.');
                }
            }
            if (in_array($document->getOriginal('status'), ['autorizado', 'cancelado', 'denegado'], true)
                && $document->isDirty('configuration_snapshot')) {
                throw new \RuntimeException('A configuração utilizada por um documento autorizado deve ser preservada.');
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Preserve o histórico dos documentos fiscais.'));
    }

    protected $table = 'fiscal_documents';

    protected $fillable = [
        'user_id',
        'company_id',
        'fiscal_connection_id',
        'environment',
        'configuration_snapshot',
        'next_check_at',
        'issued_by',
        'document_type',
        'provider',
        'source_type',
        'source_id',
        'source_label',
        'focus_reference',
        'access_key',
        'document_number',
        'series',
        'status',
        'issued_at',
        'authorized_at',
        'issuer_document',
        'recipient_document',
        'total_amount',
        'xml_path',
        'document_url',
        'cancelation_xml_path',
        'payload_hash',
        'raw_response',
        'metadata',
        'last_sent_at',
        'last_checked_at',
        'last_webhook_at',
        'imported_at',
        'last_synced_at',
        'last_error',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'authorized_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'last_webhook_at' => 'datetime',
        'imported_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'raw_response' => 'encrypted:array',
        'metadata' => 'encrypted:array',
        'last_error' => 'encrypted:array',
        'configuration_snapshot' => 'encrypted:array',
        'next_check_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function fiscalConnection(): BelongsTo
    {
        return $this->belongsTo(FiscalConnection::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FiscalEvent::class)->latest('id');
    }

    /** Errors, documents without feedback for too long and documents whose original environment is unconfirmed. */
    public function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereIn('status', [NfeStatus::AUTHORIZATION_ERROR, NfeStatus::TRANSPORT_ERROR, NfeStatus::CANCELLATION_ERROR])
                ->orWhere(fn (Builder $stuck) => $stuck->whereIn('status', [NfeStatus::SENDING, NfeStatus::PROCESSING])
                    ->whereRaw('COALESCE(last_checked_at, last_sent_at, updated_at) < ?', [now()->subMinutes(FiscalIssueDiagnosis::STUCK_AFTER_MINUTES)]))
                ->orWhere(fn (Builder $legacy) => $legacy->whereNotNull('status')
                    ->where(fn (Builder $identity) => $identity->whereNull('fiscal_connection_id')->orWhereNull('environment')));
        });
    }

    public function isLinked(): bool
    {
        return filled($this->source_type) && filled($this->source_id);
    }
}
