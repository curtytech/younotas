<?php

namespace App\Services;

use App\Exceptions\FocusProtocolException;
use App\Exceptions\FocusRateLimitedException;
use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Models\ServiceOrder;
use App\Support\NfeStatus;
use App\Support\NfseStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class FiscalDocumentWorkflow
{
    public function __construct(protected FiscalAudit $audit) {}

    public function resolve(Sale|ServiceOrder|FiscalDocument $subject): FiscalDocument
    {
        if ($subject instanceof FiscalDocument) {
            $document = $subject->fresh();
        } else {
            $prefix = $subject instanceof Sale ? 'focus_nfe_' : 'focus_nfse_';
            $document = FiscalDocument::query()->where('source_type', $subject::class)
                ->where('source_id', $subject->id)->where('focus_reference', $subject->{$prefix.'ref'})
                ->where('fiscal_connection_id', $subject->{$prefix.'connection_id'})
                ->where('environment', $subject->{$prefix.'environment'})->first();
        }

        if (! $document || ! $document->fiscal_connection_id || ! $document->company_id || ! in_array($document->environment, ['homologacao', 'producao'], true)) {
            throw new RuntimeException('Documento sem conexão e ambiente confirmados. Reconcilie a origem da emissão antes de operar.');
        }

        return $document;
    }

    public function applyResponse(FiscalDocument $document, array $response, string $operation): FiscalDocument
    {
        return DB::transaction(function () use ($document, $response, $operation): FiscalDocument {
            $document = FiscalDocument::query()->lockForUpdate()->findOrFail($document->id);
            $statusClass = $document->document_type === 'NF-e' ? NfeStatus::class : NfseStatus::class;
            $incoming = $response['status'] === 'processando_cancelamento' ? NfeStatus::PROCESSING : $statusClass::normalize($response['status']);
            $previous = $document->status;
            $status = $statusClass::shouldReplace($previous, $incoming) ? $incoming : $previous;
            $metadata = $document->metadata ?? [];
            if ($operation === 'cancelamento' && $incoming === NfeStatus::PROCESSING) {
                $metadata['cancellation_pending'] = true;
            }
            if (in_array($incoming, [NfeStatus::CANCELED, NfeStatus::CANCELLATION_ERROR], true)) {
                unset($metadata['cancellation_pending']);
            }
            if (isset($response['numero_rps'])) {
                $metadata['numero_rps'] = $response['numero_rps'];
            }
            $url = $response['url_danfe'] ?? $response['url_danfse'] ?? $response['url'] ?? $response['caminho_danfe'] ?? $response['caminho_danfse'] ?? null;
            if (filled($url) && ! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')) {
                $url = rtrim((string) data_get($document->configuration_snapshot, 'base_url'), '/').'/'.ltrim($url, '/');
            }
            $attributes = [
                'status' => $status,
                'document_number' => $response['numero'] ?? $response['numero_nfe'] ?? $document->document_number,
                'series' => $response['serie'] ?? $response['serie_rps'] ?? $document->series,
                'document_url' => $url ?? $document->document_url,
                'raw_response' => array_replace($document->raw_response ?? [], $response, ['status' => $status]),
                'metadata' => $metadata,
                'last_error' => in_array($incoming, [NfeStatus::AUTHORIZATION_ERROR, NfeStatus::CANCELLATION_ERROR], true)
                    ? ['operation' => $operation, 'response' => $response, 'at' => now()->toIso8601String()] : null,
                'next_check_at' => in_array($status, [NfeStatus::SENDING, NfeStatus::PROCESSING], true) || ! empty($metadata['cancellation_pending']) ? now()->addMinutes(5) : null,
            ];
            if ($incoming === NfeStatus::AUTHORIZED && ! $document->authorized_at) {
                $attributes['authorized_at'] = now();
            }
            if ($operation === 'consulta') {
                $attributes['last_checked_at'] = now();
            } elseif ($operation === 'webhook') {
                $attributes['last_webhook_at'] = now();
            }
            $accessKey = preg_replace('/\D/', '', (string) ($response['chave_nfe'] ?? ''));
            if (strlen($accessKey) === 44 && ! FiscalDocument::query()->where('company_id', $document->company_id)->where('access_key', $accessKey)->whereKeyNot($document->id)->exists()) {
                $attributes['access_key'] = $accessKey;
            }
            $document->fill($attributes)->save();
            $this->syncSource($document);
            $this->audit->record($document, 'document_'.$operation, ['previous_status' => $previous, 'status' => $status, 'operation' => $operation]);

            return $document->fresh();
        });
    }

    public function recordFailure(FiscalDocument $document, Throwable $exception, string $operation): void
    {
        DB::transaction(function () use ($document, $exception, $operation): void {
            $document = FiscalDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($operation === 'emissao' && in_array($document->status, [NfeStatus::AUTHORIZED, NfeStatus::CANCELED, NfeStatus::DENIED], true)) {
                $this->audit->record($document, 'stale_operation_failed', ['operation' => $operation, 'exception' => $exception::class]);

                return;
            }
            $httpStatus = $exception instanceof FocusProtocolException ? null : (property_exists($exception, 'status') ? $exception->status : null);
            $ambiguous = self::isAmbiguous($exception);
            $attributes = ['last_error' => [
                'operation' => $operation,
                'message' => $ambiguous ? 'Não foi possível confirmar o resultado na Focus. A referência será consultada novamente.' : ($httpStatus ? 'A Focus recusou a operação (HTTP '.$httpStatus.').' : $exception->getMessage()),
                'exception' => $exception::class, 'http_status' => $httpStatus ?? ($ambiguous ? null : 0), 'at' => now()->toIso8601String(),
            ]];
            if ($operation === 'emissao' && ! in_array($document->status, [NfeStatus::AUTHORIZED, NfeStatus::CANCELED, NfeStatus::DENIED], true)) {
                $attributes['status'] = NfeStatus::TRANSPORT_ERROR;
            }
            if ($operation === 'consulta') {
                $attributes['last_checked_at'] = now();
            }
            $attributes['next_check_at'] = $ambiguous || $operation === 'consulta' && in_array($document->status, [NfeStatus::SENDING, NfeStatus::PROCESSING], true) ? now()->addMinutes(5) : null;
            $document->fill($attributes)->save();
            $this->syncSource($document);
            $this->audit->record($document, 'operation_failed', ['operation' => $operation, 'http_status' => $httpStatus, 'exception' => $exception::class]);
        });
    }

    public static function isAmbiguous(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException || $exception instanceof FocusProtocolException
            || $exception instanceof FocusRateLimitedException
            || property_exists($exception, 'status') && ($exception->status >= 500 || in_array($exception->status, [408, 429], true));
    }

    public function syncSource(FiscalDocument $document): void
    {
        if (! in_array($document->source_type, [Sale::class, ServiceOrder::class], true)) {
            return;
        }
        $source = $document->source_type::query()->lockForUpdate()->find($document->source_id);
        $prefix = $source instanceof Sale ? 'focus_nfe_' : 'focus_nfse_';
        if (! $source || $source->company_id !== $document->company_id || $source->{$prefix.'ref'} !== $document->focus_reference
            || (int) $source->{$prefix.'connection_id'} !== (int) $document->fiscal_connection_id || $source->{$prefix.'environment'} !== $document->environment) {
            return;
        }
        $source->forceFill([
            $prefix.'status' => $document->status,
            $prefix.'number' => $document->document_number ?? data_get($document->metadata, 'numero_rps'),
            $prefix.'url' => $document->document_url,
            $prefix.'response_secure' => $document->raw_response,
            $prefix.'payload' => data_get($document->metadata, 'focus_payload'),
            $prefix.'error' => $document->last_error,
            $prefix.'last_sent_at' => $document->last_sent_at,
            $prefix.'last_checked_at' => $document->last_checked_at,
            $prefix.'last_webhook_at' => $document->last_webhook_at,
            $prefix.'attempts' => (int) data_get($document->metadata, 'focus_attempts', 0),
        ])->save();
        if ($source instanceof ServiceOrder && $document->environment === 'producao') {
            if ($document->status === NfseStatus::AUTHORIZED && $source->status === 'completed') {
                $source->update(['status' => 'billed']);
            } elseif ($document->status === NfseStatus::CANCELED && $source->status === 'billed') {
                $source->update(['status' => 'completed']);
            }
        }
    }
}
