<?php

namespace App\Actions;

use App\Models\FocusNfseWebhookEvent;
use App\Models\Service;
use App\Support\NfseStatus;
use Illuminate\Support\Facades\DB;

class HandleFocusNfseWebhookAction
{
    public function execute(array $payload): ?Service
    {
        $reference = $payload['ref'] ?? $payload['referencia'] ?? null;
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($payload, $reference, $payloadHash): ?Service {
            $event = FocusNfseWebhookEvent::firstOrCreate(
                ['payload_hash' => $payloadHash],
                ['reference' => $reference, 'payload' => $payload],
            );

            if ($event->processed_at) {
                return $event->service;
            }

            $service = filled($reference)
                ? Service::query()->where('focus_nfse_ref', $reference)->lockForUpdate()->first()
                : null;

            if (! $service) {
                return null;
            }

            $incomingStatus = $payload['status'] ?? null;
            $status = NfseStatus::shouldReplace($service->focus_nfse_status, $incomingStatus)
                ? $incomingStatus
                : $service->focus_nfse_status;

            $service->update([
                'focus_nfse_status' => $status,
                'focus_nfse_number' => $payload['numero'] ?? $payload['numero_rps'] ?? $service->focus_nfse_number,
                'focus_nfse_url' => $payload['url'] ?? $payload['url_danfe'] ?? $service->focus_nfse_url,
                'focus_nfse_response_secure' => array_replace($service->focus_nfse_response_secure ?? [], $payload),
                'focus_nfse_response' => null,
                'focus_nfse_error' => in_array($status, [NfseStatus::AUTHORIZATION_ERROR, NfseStatus::CANCELLATION_ERROR], true)
                    ? ['response' => $payload, 'at' => now()->toIso8601String()]
                    : $service->focus_nfse_error,
            ]);

            $event->update(['service_id' => $service->id, 'processed_at' => now()]);

            return $service;
        });
    }
}
