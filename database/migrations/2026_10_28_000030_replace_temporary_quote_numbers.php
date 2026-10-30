<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('quotes')
            ->where('number', 'like', 'TMP-%')
            ->orderBy('id')
            ->eachById(function (object $quote): void {
                DB::table('quotes')
                    ->where('id', $quote->id)
                    ->update([
                        'number' => 'ORC-'.str_pad((string) $quote->id, 6, '0', STR_PAD_LEFT),
                    ]);
            });
    }

    public function down(): void
    {
        // Os números definitivos não podem ser revertidos para identificadores temporários.
    }
};
