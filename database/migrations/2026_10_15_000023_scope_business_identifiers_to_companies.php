<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $identifiers = [
        'clients' => [['email'], ['document_type', 'document']],
        'products' => [['code']],
        'services' => [['code']],
        'sales' => [['number']],
        'service_orders' => [['number']],
        'fines' => [['ait']],
    ];

    public function up(): void
    {
        foreach ($this->identifiers as $name => $identifiers) {
            foreach ($identifiers as $columns) {
                Schema::table($name, function (Blueprint $table) use ($columns): void {
                    $table->dropUnique(array_merge(['user_id'], $columns));
                    $table->unique(array_merge(['company_id'], $columns));
                });
            }
        }
        // References are scoped to a Focus connection, including in compatibility fields.
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropUnique(['focus_nfe_ref']);
            $table->unique(['focus_nfe_connection_id', 'focus_nfe_ref'], 'sales_fiscal_reference_unique');
        });
        Schema::table('service_orders', function (Blueprint $table): void {
            $table->dropUnique(['focus_nfse_ref']);
            $table->unique(['focus_nfse_connection_id', 'focus_nfse_ref'], 'service_orders_fiscal_reference_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('A restauração do modelo por usuário exige backup: empresas compartilhadas podem possuir identificadores iguais.');
    }
};
