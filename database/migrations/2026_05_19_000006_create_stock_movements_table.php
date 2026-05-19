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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->enum('movement_type', ['entry', 'exit', 'adjustment']);
            $table->enum('source_type', ['manual', 'sale', 'return', 'inventory_adjustment']);
            $table->string('reference')->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('previous_stock', 15, 3)->default(0);
            $table->decimal('current_stock', 15, 3)->default(0);
            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('moved_at');
            $table->timestamps();

            $table->index(['user_id', 'product_id']);
            $table->index(['user_id', 'movement_type']);
            $table->index(['user_id', 'source_type']);
            $table->index(['user_id', 'moved_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
