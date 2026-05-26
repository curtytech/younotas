<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->string('focus_nfse_ref')->nullable()->after('notes')->index();
            $table->string('focus_nfse_status')->nullable()->after('focus_nfse_ref')->index();
            $table->string('focus_nfse_number')->nullable()->after('focus_nfse_status');
            $table->string('focus_nfse_url')->nullable()->after('focus_nfse_number');
            $table->json('focus_nfse_response')->nullable()->after('focus_nfse_url');
            $table->timestamp('focus_nfse_last_sent_at')->nullable()->after('focus_nfse_response');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn([
                'focus_nfse_ref',
                'focus_nfse_status',
                'focus_nfse_number',
                'focus_nfse_url',
                'focus_nfse_response',
                'focus_nfse_last_sent_at',
            ]);
        });
    }
};
