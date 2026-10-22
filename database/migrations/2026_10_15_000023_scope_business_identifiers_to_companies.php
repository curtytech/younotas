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
            // MySQL may use the composite unique index to back the user_id foreign key.
            // Keep a dedicated index so the old unique can be dropped.
            if (! Schema::hasIndex($name, ['user_id'])) {
                Schema::table($name, fn (Blueprint $table) => $table->index('user_id'));
            }

            foreach ($identifiers as $columns) {
                $this->replaceUnique($name, array_merge(['user_id'], $columns), array_merge(['company_id'], $columns));
            }
        }

        // References are scoped to a Focus connection, including in compatibility fields.
        $this->replaceUnique('sales', ['focus_nfe_ref'], ['focus_nfe_connection_id', 'focus_nfe_ref'], 'sales_fiscal_reference_unique');
        $this->replaceUnique('service_orders', ['focus_nfse_ref'], ['focus_nfse_connection_id', 'focus_nfse_ref'], 'service_orders_fiscal_reference_unique');
    }

    /** Idempotent so a run interrupted by a non-transactional DDL engine can be repeated. */
    private function replaceUnique(string $table, array $old, array $new, ?string $newName = null): void
    {
        if (! Schema::hasIndex($table, $new, 'unique')) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($new, $newName));
        }

        if (Schema::hasIndex($table, $old, 'unique')) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($old));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('A restauração do modelo por usuário exige backup: empresas compartilhadas podem possuir identificadores iguais.');
    }
};
