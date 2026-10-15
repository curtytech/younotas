<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The product decision is to start the new model without carrying over
        // the legacy service/NFS-e records.
        DB::statement('DROP VIEW IF EXISTS fiscal_documents');
        Schema::dropIfExists('focus_nfse_webhook_events');
        Schema::dropIfExists('service_order_attachments');
        Schema::dropIfExists('service_order_items');
        Schema::dropIfExists('service_orders');
        Schema::dropIfExists('technicians');
        Schema::dropIfExists('services');

        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code')->nullable();
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
        });

        Schema::create('technicians', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'name']);
        });

        Schema::create('service_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number');
            $table->enum('status', ['draft', 'scheduled', 'in_progress', 'completed', 'billed', 'canceled'])->default('draft')->index();
            $table->timestamp('scheduled_for')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('problem_description')->nullable();
            $table->text('execution_description')->nullable();
            $table->decimal('subtotal_amount', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('signed_by_name')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('focus_nfse_ref')->nullable()->unique();
            $table->string('focus_nfse_status')->nullable()->index();
            $table->string('focus_nfse_number')->nullable();
            $table->string('focus_nfse_url')->nullable();
            $table->json('focus_nfse_response')->nullable();
            $table->longText('focus_nfse_response_secure')->nullable();
            $table->longText('focus_nfse_payload')->nullable();
            $table->longText('focus_nfse_error')->nullable();
            $table->unsignedSmallInteger('focus_nfse_attempts')->default(0);
            $table->timestamp('focus_nfse_last_sent_at')->nullable();
            $table->timestamp('focus_nfse_last_checked_at')->nullable();
            $table->timestamp('focus_nfse_last_webhook_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'number']);
            $table->index(['user_id', 'client_id']);
            $table->index(['user_id', 'technician_id']);
        });

        Schema::create('service_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_name');
            $table->string('service_code')->nullable();
            $table->text('description')->nullable();
            $table->string('municipal_service_code')->nullable();
            $table->string('lc116_code')->nullable();
            $table->string('cnae_code')->nullable();
            $table->string('nbs_code')->nullable();
            $table->string('unit', 20)->default('UN');
            $table->decimal('quantity', 15, 3)->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('iss_aliquot', 5, 2)->default(0);
            $table->decimal('pis_aliquot', 5, 2)->default(0);
            $table->decimal('cofins_aliquot', 5, 2)->default(0);
            $table->decimal('inss_aliquot', 5, 2)->default(0);
            $table->decimal('ir_aliquot', 5, 2)->default(0);
            $table->decimal('csll_aliquot', 5, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->timestamps();
            $table->index(['service_order_id', 'service_id']);
        });

        Schema::create('service_order_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('focus_nfse_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->nullable()->index();
            $table->string('payload_hash', 64)->unique();
            $table->longText('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        self::createFiscalDocumentsView();
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS fiscal_documents');
        Schema::dropIfExists('focus_nfse_webhook_events');
        Schema::dropIfExists('service_order_attachments');
        Schema::dropIfExists('service_order_items');
        Schema::dropIfExists('service_orders');
        Schema::dropIfExists('technicians');
        Schema::dropIfExists('services');
    }

    private static function createFiscalDocumentsView(): void
    {
        $concatNfe = DB::connection()->getDriverName() === 'sqlite' ? "'NF-e-' || sales.id" : "CONCAT('NF-e-', sales.id)";
        $concatNfse = DB::connection()->getDriverName() === 'sqlite' ? "'NFS-e-' || service_orders.id" : "CONCAT('NFS-e-', service_orders.id)";
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
                service_orders.focus_nfse_last_sent_at AS last_sent_at, service_orders.focus_nfse_last_checked_at AS last_checked_at,
                service_orders.focus_nfse_last_webhook_at AS last_webhook_at
            FROM service_orders
            WHERE service_orders.focus_nfse_ref IS NOT NULL OR service_orders.focus_nfse_status IS NOT NULL");
    }
};
