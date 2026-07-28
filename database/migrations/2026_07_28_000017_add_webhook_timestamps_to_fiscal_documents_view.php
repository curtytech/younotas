<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS fiscal_documents');

        $concatNfe = DB::connection()->getDriverName() === 'sqlite'
            ? "'NF-e-' || sales.id"
            : "CONCAT('NF-e-', sales.id)";
        $concatNfse = DB::connection()->getDriverName() === 'sqlite'
            ? "'NFS-e-' || services.id"
            : "CONCAT('NFS-e-', services.id)";

        DB::statement("CREATE VIEW fiscal_documents AS
            SELECT
                {$concatNfe} AS id,
                'NF-e' AS document_type,
                sales.id AS source_id,
                sales.user_id,
                sales.number AS source_label,
                sales.sale_date AS issued_at,
                sales.focus_nfe_status AS status,
                sales.focus_nfe_ref AS reference,
                sales.focus_nfe_number AS document_number,
                sales.focus_nfe_url AS document_url,
                sales.focus_nfe_last_sent_at AS last_sent_at,
                sales.focus_nfe_last_checked_at AS last_checked_at,
                sales.focus_nfe_last_webhook_at AS last_webhook_at
            FROM sales
            WHERE sales.focus_nfe_ref IS NOT NULL OR sales.focus_nfe_status IS NOT NULL
            UNION ALL
            SELECT
                {$concatNfse} AS id,
                'NFS-e' AS document_type,
                services.id AS source_id,
                services.user_id,
                services.code AS source_label,
                services.focus_nfse_last_sent_at AS issued_at,
                services.focus_nfse_status AS status,
                services.focus_nfse_ref AS reference,
                services.focus_nfse_number AS document_number,
                services.focus_nfse_url AS document_url,
                services.focus_nfse_last_sent_at AS last_sent_at,
                services.focus_nfse_last_checked_at AS last_checked_at,
                services.focus_nfse_last_webhook_at AS last_webhook_at
            FROM services
            WHERE services.focus_nfse_ref IS NOT NULL OR services.focus_nfse_status IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS fiscal_documents');

        $concatNfe = DB::connection()->getDriverName() === 'sqlite'
            ? "'NF-e-' || sales.id"
            : "CONCAT('NF-e-', sales.id)";
        $concatNfse = DB::connection()->getDriverName() === 'sqlite'
            ? "'NFS-e-' || services.id"
            : "CONCAT('NFS-e-', services.id)";

        DB::statement("CREATE VIEW fiscal_documents AS
            SELECT {$concatNfe} AS id, 'NF-e' AS document_type, sales.id AS source_id, sales.user_id,
                sales.number AS source_label, sales.sale_date AS issued_at, sales.focus_nfe_status AS status,
                sales.focus_nfe_ref AS reference, sales.focus_nfe_number AS document_number,
                sales.focus_nfe_url AS document_url, sales.focus_nfe_last_sent_at AS last_sent_at,
                sales.focus_nfe_last_checked_at AS last_checked_at
            FROM sales WHERE sales.focus_nfe_ref IS NOT NULL OR sales.focus_nfe_status IS NOT NULL
            UNION ALL
            SELECT {$concatNfse} AS id, 'NFS-e' AS document_type, services.id AS source_id, services.user_id,
                services.code AS source_label, services.focus_nfse_last_sent_at AS issued_at, services.focus_nfse_status AS status,
                services.focus_nfse_ref AS reference, services.focus_nfse_number AS document_number,
                services.focus_nfse_url AS document_url, services.focus_nfse_last_sent_at AS last_sent_at,
                services.focus_nfse_last_checked_at AS last_checked_at
            FROM services WHERE services.focus_nfse_ref IS NOT NULL OR services.focus_nfse_status IS NOT NULL
        ");
    }
};
