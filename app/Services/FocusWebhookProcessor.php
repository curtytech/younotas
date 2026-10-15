<?php

namespace App\Services;

use App\Models\FiscalDocument;
use App\Models\FocusNfeWebhookEvent;
use App\Models\FocusNfseWebhookEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class FocusWebhookProcessor
{
    public function __construct(protected FiscalDocumentWorkflow $workflow) {}

    public function capture(string $type, array $payload): Model
    {
        $model = $type === 'NF-e' ? FocusNfeWebhookEvent::class : FocusNfseWebhookEvent::class;
        $canonical = $this->canonical($payload);

        return $model::firstOrCreate([
            'payload_hash' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)),
        ], [
            'reference' => $payload['ref'] ?? $payload['referencia'] ?? null,
            'payload' => $payload,
        ]);
    }

    public function process(string $type, int $eventId): ?FiscalDocument
    {
        $model = $type === 'NF-e' ? FocusNfeWebhookEvent::class : FocusNfseWebhookEvent::class;

        return DB::transaction(function () use ($model, $type, $eventId): ?FiscalDocument {
            $event = $model::query()->lockForUpdate()->findOrFail($eventId);
            $payload = $event->payload;
            $document = $this->match($type, $event->reference, $payload);
            if (! $document) {
                $event->touch();

                return null;
            }
            if ($event->processed_at) {
                return $document;
            }
            if (! is_string($payload['status'] ?? null) || ! in_array($payload['status'], [
                'autorizado', 'cancelado', 'denegado', 'processando', 'processando_autorizacao',
                'processando_cancelamento', 'enviando', 'erro_autorizacao', 'erro_cancelamento',
            ], true)) {
                $event->touch();

                return null;
            }
            $document = $this->workflow->applyResponse($document, $payload, 'webhook');
            $event->update([
                $type === 'NF-e' ? 'sale_id' : 'service_order_id' => $document->source_id,
                'processed_at' => now(),
            ]);

            return $document;
        });
    }

    protected function match(string $type, ?string $reference, array $payload): ?FiscalDocument
    {
        if (blank($reference)) {
            return null;
        }
        $query = FiscalDocument::query()->where('document_type', $type)->where('provider', 'focus')
            ->where('focus_reference', $reference)->whereNotNull('fiscal_connection_id')->whereNotNull('environment');
        $environmentValue = $payload['ambiente'] ?? $payload['environment'] ?? $payload['tpAmb'] ?? $payload['tp_amb'] ?? null;
        if ($environmentValue !== null) {
            $environment = match ((string) $environmentValue) {
                '1', 'producao', 'produção', 'production' => 'producao',
                '2', 'homologacao', 'homologação', 'homologation' => 'homologacao',
                default => null,
            };
            if (! $environment) {
                return null;
            }
            $query->where('environment', $environment);
        }
        $issuer = $payload['cnpj_emitente'] ?? $payload['cnpj_prestador'] ?? data_get($payload, 'prestador.cnpj') ?? data_get($payload, 'emitente.cnpj');
        $documents = $query->get();
        if ($issuer !== null) {
            $issuer = preg_replace('/\D/', '', (string) $issuer);
            if (strlen($issuer) !== 14) {
                return null;
            }
            $documents = $documents->filter(fn (FiscalDocument $document): bool => preg_replace('/\D/', '', (string) ($document->issuer_document ?: data_get($document->configuration_snapshot, 'prestador.cnpj'))) === $issuer);
        }

        return $documents->count() === 1 ? $documents->first() : null;
    }

    protected function canonical(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonical($value);
            }
        }

        return $payload;
    }
}
