<?php

namespace App\Actions;

use App\Models\FocusNfseWebhookEvent;
use App\Models\FiscalDocument;
use App\Models\ServiceOrder;
use App\Support\NfseStatus;
use Illuminate\Support\Facades\DB;

class HandleFocusNfseWebhookAction
{
    public function execute(array $payload): ?ServiceOrder
    {
        $reference = $payload['ref'] ?? $payload['referencia'] ?? null;
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($payload, $reference, $payloadHash): ?ServiceOrder {
            $event = FocusNfseWebhookEvent::firstOrCreate(
                ['payload_hash' => $payloadHash],
                ['reference' => $reference, 'payload' => $payload],
            );

            if ($event->processed_at) {
                return $event->serviceOrder;
            }

            $service = filled($reference)
                ? ServiceOrder::query()->where('focus_nfse_ref', $reference)->lockForUpdate()->first()
                : null;

            if (! $service) {
                return null;
            }

            $incomingStatus = NfseStatus::normalize($payload['status'] ?? null);
            $status = NfseStatus::shouldReplace($service->focus_nfse_status, $incomingStatus)
                ? $incomingStatus
                : $service->focus_nfse_status;

            $service->update([
                'focus_nfse_status' => $status,
                'focus_nfse_number' => $payload['numero'] ?? $payload['numero_rps'] ?? $service->focus_nfse_number,
                'focus_nfse_url' => $this->resolveDocumentUrl($payload, $service),
                'focus_nfse_last_webhook_at' => now(),
                'focus_nfse_response_secure' => array_replace($service->focus_nfse_response_secure ?? [], $payload),
                'focus_nfse_response' => null,
                'focus_nfse_error' => in_array($status, [NfseStatus::AUTHORIZATION_ERROR, NfseStatus::CANCELLATION_ERROR], true)
                    ? ['response' => $payload, 'at' => now()->toIso8601String()]
                    : $service->focus_nfse_error,
            ]);

            if ($status === NfseStatus::AUTHORIZED) {
                $service->update(['status' => 'billed']);
            } elseif ($status === NfseStatus::CANCELED) {
                $service->update(['status' => 'completed']);
            }

            FiscalDocument::syncFromServiceOrder($service->fresh());

            $event->update(['service_order_id' => $service->id, 'processed_at' => now()]);

            return $service;
        });
    }

    private function resolveDocumentUrl(array $payload, ServiceOrder $service): ?string
    {
        $url = $payload['url'] ?? $payload['url_danfe'] ?? $payload['caminho_danfse'] ?? $payload['caminho_danfe'] ?? null;

        if (blank($url)) {
            return $service->focus_nfse_url;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $baseUrl = data_get($service->user?->focusNfeSetting?->settings, 'base_url', config('services.focus_nfe.base_url'));

        return rtrim((string) $baseUrl, '/').'/'.ltrim($url, '/');
    }
}
