<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->timestamp('focus_nfe_last_webhook_at')->nullable()->after('focus_nfe_last_checked_at');
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->timestamp('focus_nfse_last_webhook_at')->nullable()->after('focus_nfse_last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn('focus_nfe_last_webhook_at');
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('focus_nfse_last_webhook_at');
        });
    }
};
