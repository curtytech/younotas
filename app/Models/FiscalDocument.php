<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalDocument extends Model
{
    protected $table = 'fiscal_documents';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'issued_at' => 'date',
        'last_sent_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];
}
