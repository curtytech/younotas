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
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('client_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('municipal_service_code')->nullable();
            $table->string('lc116_code')->nullable();
            $table->string('cnae_code')->nullable();
            $table->string('nbs_code')->nullable();
            $table->string('unit', 20)->default('UN');
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('iss_aliquot', 5, 2)->default(0);
            $table->decimal('pis_aliquot', 5, 2)->default(0);
            $table->decimal('cofins_aliquot', 5, 2)->default(0);
            $table->decimal('inss_aliquot', 5, 2)->default(0);
            $table->decimal('ir_aliquot', 5, 2)->default(0);
            $table->decimal('csll_aliquot', 5, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'code']);
            $table->index(['user_id', 'name']);
            $table->index(['user_id', 'municipal_service_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
