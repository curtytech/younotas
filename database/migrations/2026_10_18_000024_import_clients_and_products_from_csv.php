<?php

use App\Services\CatalogCsvImportService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CatalogCsvImportService::class)->import(
            2,
            config('catalog_import.clients_path'),
            config('catalog_import.products_path'),
            storage_path('app/private/catalog-import-user-2-report.json'),
        );
    }

    public function down(): void
    {
        // Imported entities may already belong to sales and service orders.
        throw new RuntimeException('Importacao de dados irreversivel automaticamente. Preserve clientes, produtos e historico de estoque.');
    }
};
