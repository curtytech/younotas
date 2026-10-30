<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quotes') && ! Schema::hasColumn('quotes', 'company_id')) {
            Schema::table('quotes', function (Blueprint $table): void {
                $table->unsignedBigInteger('company_id')->nullable()->index();
            });

            DB::statement(
                'UPDATE quotes SET company_id = (SELECT clients.company_id FROM clients WHERE clients.id = quotes.client_id) WHERE company_id IS NULL'
            );
        }

        if (Schema::hasTable('quote_items') && ! Schema::hasColumn('quote_items', 'name')) {
            Schema::table('quote_items', function (Blueprint $table): void {
                $table->string('name')->nullable();
            });

            if (Schema::hasColumn('quote_items', 'item_name')) {
                DB::statement('UPDATE quote_items SET name = item_name WHERE name IS NULL');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('quote_items') && Schema::hasColumn('quote_items', 'name')) {
            Schema::table('quote_items', function (Blueprint $table): void {
                $table->dropColumn('name');
            });
        }

        if (Schema::hasTable('quotes') && Schema::hasColumn('quotes', 'company_id')) {
            Schema::table('quotes', function (Blueprint $table): void {
                $table->dropColumn('company_id');
            });
        }
    }
};
