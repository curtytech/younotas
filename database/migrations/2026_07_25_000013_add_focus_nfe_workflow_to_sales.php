<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('focus_nfe_ref')->nullable()->unique()->after('issue_invoice');
            $table->string('focus_nfe_status')->nullable()->index()->after('focus_nfe_ref');
            $table->string('focus_nfe_number')->nullable()->after('focus_nfe_status');
            $table->string('focus_nfe_url')->nullable()->after('focus_nfe_number');
            $table->longText('focus_nfe_response_secure')->nullable()->after('focus_nfe_url');
            $table->longText('focus_nfe_payload')->nullable()->after('focus_nfe_response_secure');
            $table->longText('focus_nfe_error')->nullable()->after('focus_nfe_payload');
            $table->unsignedSmallInteger('focus_nfe_attempts')->default(0)->after('focus_nfe_error');
            $table->timestamp('focus_nfe_last_sent_at')->nullable()->after('focus_nfe_attempts');
            $table->timestamp('focus_nfe_last_checked_at')->nullable()->after('focus_nfe_last_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn([
                'focus_nfe_ref', 'focus_nfe_status', 'focus_nfe_number', 'focus_nfe_url',
                'focus_nfe_response_secure', 'focus_nfe_payload', 'focus_nfe_error',
                'focus_nfe_attempts', 'focus_nfe_last_sent_at', 'focus_nfe_last_checked_at',
            ]);
        });
    }
};
