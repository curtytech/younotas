<?php

namespace App\Actions;

use App\Exceptions\FocusNfseRequestException;
use App\Jobs\ReconcileFocusNfseWebhooksJob;
use App\Models\Service;
use App\Services\FocusNfseService;
use App\Support\NfseStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EmitServiceNfseAction
{
    public function __construct(
        protected FocusNfseService $focusNfseService,
        protected ConsultServiceNfseAction $consultServiceNfseAction,
    ) {}

    public function execute(Service $service): array
    {
        [$prepared, $reference, $payload, $config] = DB::transaction(function () use ($service): array {
            $locked = Service::query()->lockForUpdate()->findOrFail($service->id);

            if (! NfseStatus::canEmit($locked->focus_nfse_status)) {
                throw new RuntimeException('A NFS-e não pode ser emitida no estado atual: '.$locked->focus_nfse_status.'.');
            }

            $config = $this->focusNfseService->configurationFor($locked);
            $reference = $locked->focus_nfse_ref ?: (string) Str::uuid();
            $payload = $locked->focus_nfse_payload ?: $this->focusNfseService->buildPayload($locked, $config);

            $locked->update([
                'focus_nfse_ref' => $reference,
                'focus_nfse_status' => NfseStatus::SENDING,
                'focus_nfse_payload' => $payload,
                'focus_nfse_error' => null,
                'focus_nfse_attempts' => $locked->focus_nfse_attempts + 1,
                'focus_nfse_last_sent_at' => now(),
            ]);

            return [$locked, $reference, $payload, $config];
        });

        ReconcileFocusNfseWebhooksJob::dispatch($reference);

        try {
            $response = $this->focusNfseService->emit($config, $reference, $payload);
        } catch (Throwable $exception) {
            if ($this->mayHaveBeenProcessed($exception)) {
                try {
                    return $this->consultServiceNfseAction->execute(Service::findOrFail($prepared->id));
                } catch (Throwable) {
                    // A consulta pode retornar 404 enquanto a Focus ainda processa a primeira tentativa.
                }
            }

            $this->recordFailure($prepared->id, $reference, $exception);

            throw $exception;
        }

        $this->persistResponse($prepared->id, $reference, $response);

        return $response;
    }

    protected function mayHaveBeenProcessed(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || $exception instanceof FocusNfseRequestException && $exception->mayHaveBeenProcessed();
    }

    protected function recordFailure(int $serviceId, string $reference, Throwable $exception): void
    {
        $service = Service::query()->whereKey($serviceId)->where('focus_nfse_ref', $reference)->first();

        if (! $service) {
            return;
        }

        $service->update([
            'focus_nfse_status' => NfseStatus::TRANSPORT_ERROR,
            'focus_nfse_error' => [
                'operation' => 'emissao',
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'http_status' => $exception instanceof FocusNfseRequestException ? $exception->status : null,
                'at' => now()->toIso8601String(),
            ],
        ]);
    }

    protected function persistResponse(int $serviceId, string $reference, array $response): void
    {
        $service = Service::query()->whereKey($serviceId)->where('focus_nfse_ref', $reference)->firstOrFail();
        $status = $response['status'] ?? NfseStatus::PROCESSING;

        $service->update([
            'focus_nfse_status' => $status,
            'focus_nfse_number' => $response['numero'] ?? $response['numero_rps'] ?? null,
            'focus_nfse_url' => $response['url'] ?? $response['url_danfe'] ?? null,
            'focus_nfse_response_secure' => $response,
            'focus_nfse_response' => null,
            'focus_nfse_error' => in_array($status, [NfseStatus::AUTHORIZATION_ERROR, NfseStatus::CANCELLATION_ERROR], true)
                ? ['operation' => 'emissao', 'response' => $response, 'at' => now()->toIso8601String()]
                : null,
        ]);
    }
}
