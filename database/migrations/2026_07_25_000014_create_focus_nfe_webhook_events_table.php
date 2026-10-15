<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('focus_nfe_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->nullable()->index();
            $table->string('payload_hash', 64)->unique();
            $table->longText('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_nfe_webhook_events');
    }
};
