<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('indicador_ie', 1)->nullable();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('cfop_interestadual', 4)->nullable();
            $table->string('icms_origem', 1)->nullable();
            $table->string('pis_cst', 2)->nullable();
            $table->string('cofins_cst', 2)->nullable();
        });

        Schema::table('service_orders', function (Blueprint $table): void {
            $table->boolean('iss_retido')->default(false);
            $table->string('codigo_municipio_prestacao', 7)->nullable();
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->decimal('icms_base_calculo', 14, 2)->nullable();
            $table->decimal('pis_base_calculo', 14, 2)->nullable();
            $table->decimal('cofins_base_calculo', 14, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn(['icms_base_calculo', 'pis_base_calculo', 'cofins_base_calculo']));
        Schema::table('service_orders', fn (Blueprint $table) => $table->dropColumn(['iss_retido', 'codigo_municipio_prestacao']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['cfop_interestadual', 'icms_origem', 'pis_cst', 'cofins_cst']));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('indicador_ie'));
    }
};
