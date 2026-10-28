<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quote_items') || ! Schema::hasColumn('quote_items', 'item_name')) {
            return;
        }

        Schema::table('quote_items', function (Blueprint $table): void {
            $table->string('item_name')->nullable()->change();
        });

        if (Schema::hasColumn('quote_items', 'name')) {
            DB::statement('UPDATE quote_items SET item_name = name WHERE item_name IS NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('quote_items') && Schema::hasColumn('quote_items', 'item_name')) {
            Schema::table('quote_items', function (Blueprint $table): void {
                $table->string('item_name')->nullable(false)->change();
            });
        }
    }
};
