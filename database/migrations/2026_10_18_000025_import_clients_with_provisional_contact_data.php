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
            storage_path('app/private/catalog-import-user-2-provisional-report.json'),
            fillMissing: true,
        );
    }

    public function down(): void
    {
        throw new RuntimeException('Importacao de dados irreversivel automaticamente; preserve os cadastros e seu historico.');
    }
};
