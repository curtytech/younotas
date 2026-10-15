<?php

namespace App\Services;

use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Models\ServiceOrder;
use App\Support\NfeStatus;
use App\Support\NfseStatus;

/** Keeps the commercial compatibility fields in sync without changing fiscal identity. */
class FiscalDocumentRecorder
{
    public function recordSale(Sale $sale, array $extra = []): ?FiscalDocument
    {
        return $this->record($sale, 'focus_nfe', 'NF-e', [
            'document_number' => $sale->focus_nfe_number,
            'series' => $this->stringOrNull($sale->focus_nfe_response_secure['serie'] ?? null),
            'access_key' => $this->accessKey($sale->focus_nfe_response_secure['chave_nfe'] ?? null),
            'issued_at' => $sale->focus_nfe_payload['data_emissao'] ?? $sale->sale_date,
        ], $extra);
    }

    public function recordServiceOrder(ServiceOrder $order, array $extra = []): ?FiscalDocument
    {
        return $this->record($order, 'focus_nfse', 'NFS-e', [
            'document_number' => $this->stringOrNull($order->focus_nfse_response_secure['numero'] ?? null)
                ?? ($order->focus_nfse_status === NfseStatus::AUTHORIZED ? $order->focus_nfse_number : null),
            'series' => $this->stringOrNull($order->focus_nfse_response_secure['serie_rps'] ?? null),
            'issued_at' => $order->focus_nfse_payload['data_emissao'] ?? $order->completed_at,
            'metadata' => array_filter(['numero_rps' => $order->focus_nfse_response_secure['numero_rps'] ?? null]),
        ], $extra);
    }

    private function record(Sale|ServiceOrder $source, string $prefix, string $type, array $attributes, array $extra): ?FiscalDocument
    {
        $reference = $source->getAttribute($prefix.'_ref');
        if (blank($reference)) {
            return null;
        }

        $connectionId = $source->getAttribute($prefix.'_connection_id');
        $environment = $source->getAttribute($prefix.'_environment');
        $query = FiscalDocument::query()->where('focus_reference', $reference);
        if ($connectionId) {
            $query->where('fiscal_connection_id', $connectionId);
        } else {
            $query->where('user_id', $source->user_id)->whereNull('fiscal_connection_id');
        }
        $document = $query->first();

        // An imported XML and a local emission represent the same fiscal document.
        if (! $document && filled($attributes['access_key'] ?? null) && $source->company_id && $environment) {
            $document = FiscalDocument::query()->where('company_id', $source->company_id)
                ->where('environment', $environment)->where('access_key', $attributes['access_key'])->first();
        }
        if ($document?->isLinked() && ($document->source_type !== $source::class || (int) $document->source_id !== (int) $source->id)) {
            throw new \RuntimeException('Este documento fiscal já está vinculado a outra operação comercial. Reconcilie a origem.');
        }
        $document ??= new FiscalDocument;
        $payload = $source->getAttribute($prefix.'_payload') ?? [];
        $status = $source->getAttribute($prefix.'_status');
        $statusClass = $type === 'NF-e' ? NfeStatus::class : NfseStatus::class;
        $replace = ! $document->exists || $statusClass::shouldReplace($document->status, $status) || $document->status === $status;

        $values = array_replace([
            'user_id' => $source->user_id,
            'company_id' => $source->company_id,
            'document_type' => $type,
            'provider' => 'focus',
            'source_type' => $source::class,
            'source_id' => $source->id,
            'source_label' => $source->number,
            'focus_reference' => $reference,
            'total_amount' => $source->total_amount,
            'status' => $status,
            'document_url' => $source->getAttribute($prefix.'_url'),
            'raw_response' => $source->getAttribute($prefix.'_response_secure'),
            'metadata' => ['origin' => 'emitted'],
            'issuer_document' => data_get($payload, 'cnpj_emitente') ?? data_get($payload, 'prestador.cnpj'),
            'recipient_document' => data_get($payload, 'cnpj_destinatario') ?? data_get($payload, 'cpf_destinatario')
                ?? data_get($payload, 'tomador.cnpj') ?? data_get($payload, 'tomador.cpf'),
            'last_sent_at' => $source->getAttribute($prefix.'_last_sent_at'),
            'last_checked_at' => $source->getAttribute($prefix.'_last_checked_at'),
            'last_webhook_at' => $source->getAttribute($prefix.'_last_webhook_at'),
            'last_error' => $source->getAttribute($prefix.'_error'),
        ], $attributes, $extra);

        $values['metadata'] = array_replace(['origin' => 'emitted'], $document->metadata ?? [], $values['metadata'] ?? []);
        if ($payload !== []) {
            $values['metadata']['focus_payload'] = $payload;
            $values['payload_hash'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        }
        if (! $document->fiscal_connection_id && $connectionId) {
            $values['fiscal_connection_id'] = $connectionId;
            $values['environment'] = $environment;
            $values['configuration_snapshot'] = $source->getAttribute($prefix.'_configuration_snapshot');
        }
        if (! $replace) {
            foreach (['status', 'raw_response', 'last_error', 'document_number', 'document_url', 'series'] as $field) {
                unset($values[$field]);
            }
        }
        if (($values['status'] ?? null) === NfeStatus::AUTHORIZED && ! $document->authorized_at) {
            $values['authorized_at'] = now();
        }
        if ($document->exists) {
            // Identity already reserved by the fiscal workflow always wins.
            unset($values['user_id'], $values['company_id']);
        }
        $document->fill($values)->save();

        return $document;
    }

    private function accessKey(mixed $value): ?string
    {
        $key = preg_replace('/\D/', '', (string) $value) ?? '';
        return strlen($key) === 44 ? $key : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return filled($value) ? (string) $value : null;
    }
}
