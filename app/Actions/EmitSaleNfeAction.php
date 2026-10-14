<?php

namespace App\Actions;

use App\Exceptions\FocusNfeRequestException;
use App\Jobs\ConsultSaleNfeJob;
use App\Jobs\ReconcileFocusNfeWebhooksJob;
use App\Models\Sale;
use App\Services\FocusNfeService;
use App\Support\NfeStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EmitSaleNfeAction
{
    public function __construct(
        protected FocusNfeService $focusNfeService,
        protected ConsultSaleNfeAction $consultAction,
    ) {}

    public function execute(Sale $sale): array
    {
        [$prepared, $reference, $payload, $config] = DB::transaction(function () use ($sale): array {
            $locked = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if (! NfeStatus::canEmit($locked->focus_nfe_status)) {
                throw new RuntimeException('A NF-e não pode ser emitida no estado atual: '.$locked->focus_nfe_status.'.');
            }

            $config = $this->focusNfeService->configurationFor($locked);
            // A referência Focus deve ser alfanumérica. Ela é mantida em retries e após rejeição,
            // mas uma nota cancelada ou denegada exige nova referência (a Focus responde already_processed).
            $newReference = blank($locked->focus_nfe_ref) || NfeStatus::requiresNewReference($locked->focus_nfe_status);
            $reference = $newReference ? 'sale'.$locked->id.Str::lower(Str::random(8)) : $locked->focus_nfe_ref;
            // Retry de falha ambígua repete o payload original; nos demais casos a nota é remontada com os dados atuais.
            $reusePayload = ! $newReference
                && $locked->focus_nfe_status === NfeStatus::TRANSPORT_ERROR
                && NfeStatus::isAmbiguousFailure($locked->focus_nfe_error)
                && filled($locked->focus_nfe_payload);
            $payload = $reusePayload ? $locked->focus_nfe_payload : $this->focusNfeService->buildPayload($locked, $config);

            if ($newReference) {
                $locked->fill([
                    'focus_nfe_number' => null,
                    'focus_nfe_url' => null,
                    'focus_nfe_response_secure' => null,
                    'focus_nfe_last_checked_at' => null,
                    'focus_nfe_last_webhook_at' => null,
                ]);
            }

            $locked->update([
                'focus_nfe_ref' => $reference,
                'focus_nfe_status' => NfeStatus::SENDING,
                'focus_nfe_payload' => $payload,
                'focus_nfe_error' => null,
                'focus_nfe_attempts' => $locked->focus_nfe_attempts + 1,
                'focus_nfe_last_sent_at' => now(),
            ]);

            return [$locked, $reference, $payload, $config];
        });

        ReconcileFocusNfeWebhooksJob::dispatch($reference);

        try {
            $response = $this->focusNfeService->emit($config, $reference, $payload);
        } catch (Throwable $exception) {
            if ($exception instanceof ConnectionException || $exception instanceof FocusNfeRequestException && $exception->mayHaveBeenProcessed()) {
                try {
                    return $this->consultAction->execute(Sale::findOrFail($prepared->id));
                } catch (Throwable) {
                    // A primeira consulta pode ocorrer antes de a Focus disponibilizar a referência.
                }
            }

            $this->recordFailure($prepared->id, $reference, $exception);
            throw $exception;
        }

        $this->persistResponse($prepared->id, $reference, $response, $config);
        $sale = Sale::findOrFail($prepared->id);
        if ($sale->focus_nfe_status === NfeStatus::PROCESSING) {
            ConsultSaleNfeJob::dispatch($sale->id)->delay(now()->addMinute());
        }

        return $response;
    }

    protected function recordFailure(int $saleId, string $reference, Throwable $exception): void
    {
        $sale = Sale::query()->whereKey($saleId)->where('focus_nfe_ref', $reference)->first();
        $sale?->update(['focus_nfe_status' => NfeStatus::TRANSPORT_ERROR, 'focus_nfe_error' => [
            'operation' => 'emissao', 'message' => $exception->getMessage(), 'exception' => $exception::class,
            'http_status' => $exception instanceof FocusNfeRequestException ? $exception->status : null,
            'at' => now()->toIso8601String(),
        ]]);
    }

    protected function persistResponse(int $saleId, string $reference, array $response, array $config): void
    {
        $sale = Sale::query()->whereKey($saleId)->where('focus_nfe_ref', $reference)->firstOrFail();
        $status = NfeStatus::normalize($response['status'] ?? NfeStatus::PROCESSING);
        $sale->update([
            'focus_nfe_status' => $status,
            'focus_nfe_number' => $response['numero'] ?? $response['numero_nfe'] ?? $sale->focus_nfe_number,
            'focus_nfe_url' => $this->focusNfeService->documentUrl($response, $config) ?? $sale->focus_nfe_url,
            'focus_nfe_response_secure' => $response,
            'focus_nfe_error' => $status === NfeStatus::AUTHORIZATION_ERROR ? ['response' => $response, 'at' => now()->toIso8601String()] : null,
        ]);
    }
}
