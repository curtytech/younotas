<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS fiscal_documents');

        Schema::create('fiscal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document_type'); // NF-e | NFS-e
            $table->string('provider')->default('focus');
            $table->nullableMorphs('source'); // sale | service_order | null (importado)
            $table->string('source_label')->nullable();
            $table->string('focus_reference')->nullable();
            $table->string('access_key', 44)->nullable();
            $table->string('document_number')->nullable();
            $table->string('series')->nullable();
            $table->string('status')->nullable()->index();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->string('issuer_document')->nullable();
            $table->string('recipient_document')->nullable();
            $table->decimal('total_amount', 14, 2)->nullable();
            $table->string('xml_path')->nullable();
            $table->string('document_url')->nullable();
            $table->string('cancelation_xml_path')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->longText('raw_response')->nullable();
            $table->longText('metadata')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->longText('last_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'access_key']);
            $table->unique(['user_id', 'focus_reference']);
            $table->index(['user_id', 'document_type', 'status', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');

        $concatNfe = DB::connection()->getDriverName() === 'sqlite'
            ? "'NF-e-' || sales.id"
            : "CONCAT('NF-e-', sales.id)";
        $concatNfse = DB::connection()->getDriverName() === 'sqlite'
            ? "'NFS-e-' || service_orders.id"
            : "CONCAT('NFS-e-', service_orders.id)";

        DB::statement("CREATE VIEW fiscal_documents AS
            SELECT {$concatNfe} AS id, 'NF-e' AS document_type, sales.id AS source_id, sales.user_id,
                sales.number AS source_label, sales.sale_date AS issued_at, sales.focus_nfe_status AS status,
                sales.focus_nfe_ref AS reference, sales.focus_nfe_number AS document_number,
                sales.focus_nfe_url AS document_url, sales.focus_nfe_last_sent_at AS last_sent_at,
                sales.focus_nfe_last_checked_at AS last_checked_at, sales.focus_nfe_last_webhook_at AS last_webhook_at
            FROM sales WHERE sales.focus_nfe_ref IS NOT NULL OR sales.focus_nfe_status IS NOT NULL
            UNION ALL
            SELECT {$concatNfse} AS id, 'NFS-e' AS document_type, service_orders.id AS source_id, service_orders.user_id,
                service_orders.number AS source_label, service_orders.completed_at AS issued_at,
                service_orders.focus_nfse_status AS status, service_orders.focus_nfse_ref AS reference,
                service_orders.focus_nfse_number AS document_number, service_orders.focus_nfse_url AS document_url,
                service_orders.focus_nfse_last_sent_at AS last_sent_at,
                service_orders.focus_nfse_last_checked_at AS last_checked_at,
                service_orders.focus_nfse_last_webhook_at AS last_webhook_at
            FROM service_orders
            WHERE service_orders.focus_nfse_ref IS NOT NULL OR service_orders.focus_nfse_status IS NOT NULL");
    }
};
