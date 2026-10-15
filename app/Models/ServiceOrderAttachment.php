<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderAttachment extends Model
{
    protected $fillable = ['service_order_id', 'original_name', 'path', 'mime_type', 'size', 'uploaded_by'];

    public function order(): BelongsTo { return $this->belongsTo(ServiceOrder::class, 'service_order_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
