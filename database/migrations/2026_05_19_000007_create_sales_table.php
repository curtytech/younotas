<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('client_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('number');
            $table->date('sale_date');
            $table->enum('status', ['draft', 'pending', 'completed', 'canceled'])
                ->default('draft')
                ->index();
            $table->enum('payment_status', ['pending', 'partial', 'paid', 'refunded', 'canceled'])
                ->default('pending')
                ->index();
            $table->boolean('issue_invoice')->default(false)->index();
            $table->decimal('subtotal_amount', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'number']);
            $table->index(['user_id', 'sale_date']);
            $table->index(['user_id', 'client_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
