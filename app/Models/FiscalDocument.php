<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FiscalDocument extends Model
{
    use HasFactory;

    protected $table = 'fiscal_documents';

    protected $fillable = [
        'user_id',
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
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function isLinked(): bool
    {
        return filled($this->source_type) && filled($this->source_id);
    }
}
