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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('code');
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('ncm_code')->nullable();
            $table->string('cst_code')->nullable();
            $table->string('cfop_code')->nullable();
            $table->string('cest_code')->nullable();
            $table->string('gtin')->nullable();
            $table->string('unit', 20)->default('UN');
            $table->decimal('cost_price', 10, 2)->default(0);
            $table->decimal('sale_price', 10, 2)->default(0);
            $table->decimal('stock_quantity', 15, 3)->default(0);
            $table->decimal('minimum_stock', 15, 3)->default(0);
            $table->decimal('icms_aliquot', 5, 2)->default(0);
            $table->decimal('ipi_aliquot', 5, 2)->default(0);
            $table->decimal('pis_aliquot', 5, 2)->default(0);
            $table->decimal('cofins_aliquot', 5, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'code']);
            $table->index(['user_id', 'name']);
            $table->index(['user_id', 'barcode']);
            $table->index(['user_id', 'ncm_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
