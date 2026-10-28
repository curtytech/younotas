<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotes')) {
            Schema::create('quotes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('company_id')->constrained()->restrictOnDelete();
                $table->foreignId('client_id')->constrained()->restrictOnDelete();
                $table->string('number');
                $table->date('quote_date');
                $table->date('valid_until')->nullable();
                $table->enum('status', ['draft', 'sent', 'approved', 'rejected', 'expired'])->default('draft')->index();
                $table->decimal('discount_amount', 10, 2)->default(0);
                $table->decimal('total_amount', 10, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'number']);
                $table->index(['company_id', 'client_id']);
            });
        }

        if (! Schema::hasTable('quote_items')) {
            Schema::create('quote_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
                $table->string('item_type', 20);
                $table->string('name');
                $table->string('unit', 20)->default('UN');
                $table->decimal('quantity', 15, 3)->default(1);
                $table->decimal('unit_price', 10, 2)->default(0);
                $table->decimal('total_amount', 10, 2)->default(0);
                $table->timestamps();
                $table->index(['quote_id', 'item_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
    }
};
