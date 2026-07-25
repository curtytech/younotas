<?php

namespace App\Actions;

use App\Models\FocusNfeWebhookEvent;
use App\Models\Sale;
use App\Support\NfeStatus;
use Illuminate\Support\Facades\DB;

class HandleFocusNfeWebhookAction
{
    public function execute(array $payload): ?Sale
    {
        $reference = $payload['ref'] ?? $payload['referencia'] ?? null;
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($payload, $reference, $payloadHash): ?Sale {
            $event = FocusNfeWebhookEvent::firstOrCreate(
                ['payload_hash' => $payloadHash],
                ['reference' => $reference, 'payload' => $payload],
            );

            if ($event->processed_at) {
                return $event->sale;
            }

            $sale = filled($reference) ? Sale::query()->where('focus_nfe_ref', $reference)->lockForUpdate()->first() : null;
            if (! $sale) {
                return null;
            }

            $incoming = NfeStatus::normalize($payload['status'] ?? null);
            $status = NfeStatus::shouldReplace($sale->focus_nfe_status, $incoming) ? $incoming : $sale->focus_nfe_status;
            $sale->update([
                'focus_nfe_status' => $status,
                'focus_nfe_number' => $payload['numero'] ?? $payload['numero_nfe'] ?? $sale->focus_nfe_number,
                'focus_nfe_url' => $payload['url_danfe'] ?? $payload['url'] ?? $sale->focus_nfe_url,
                'focus_nfe_response_secure' => array_replace($sale->focus_nfe_response_secure ?? [], $payload),
                'focus_nfe_error' => $status === NfeStatus::AUTHORIZATION_ERROR ? ['response' => $payload, 'at' => now()->toIso8601String()] : $sale->focus_nfe_error,
            ]);
            $event->update(['sale_id' => $sale->id, 'processed_at' => now()]);

            return $sale;
        });
    }
}
