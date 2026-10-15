<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('ibge_code', 7)->nullable()->after('city')->index();
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->longText('focus_nfse_payload')->nullable()->after('focus_nfse_response');
            $table->longText('focus_nfse_error')->nullable()->after('focus_nfse_payload');
            $table->unsignedSmallInteger('focus_nfse_attempts')->default(0)->after('focus_nfse_error');
            $table->timestamp('focus_nfse_last_checked_at')->nullable()->after('focus_nfse_last_sent_at');
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropIndex(['focus_nfse_ref']);
            $table->unique('focus_nfse_ref');
        });

        Schema::create('focus_nfse_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->nullable()->index();
            $table->string('payload_hash', 64)->unique();
            $table->longText('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_nfse_webhook_events');

        Schema::table('services', function (Blueprint $table): void {
            $table->dropUnique(['focus_nfse_ref']);
            $table->index('focus_nfse_ref');
            $table->dropColumn([
                'focus_nfse_payload',
                'focus_nfse_error',
                'focus_nfse_attempts',
                'focus_nfse_last_checked_at',
            ]);
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('ibge_code');
        });
    }
};
