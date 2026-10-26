<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Preserve clients without email instead of inventing contact information.
        if (DB::table('clients')->whereNull('email')->exists()) {
            throw new RuntimeException('Preencha os e-mails dos clientes antes de reverter esta migração.');
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
