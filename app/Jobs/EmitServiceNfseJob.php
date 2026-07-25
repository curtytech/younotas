<?php

namespace App\Jobs;

use App\Actions\EmitServiceNfseAction;
use App\Models\Service;
use App\Support\NfseStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class EmitServiceNfseJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $uniqueFor = 600;

    public function __construct(public int $serviceId) {}

    public function uniqueId(): string
    {
        return (string) $this->serviceId;
    }

    public function handle(EmitServiceNfseAction $action): void
    {
        $service = Service::findOrFail($this->serviceId);
        $action->execute($service);
        $service->refresh();

        if ($service->focus_nfse_status === NfseStatus::PROCESSING) {
            ConsultServiceNfseJob::dispatch($service->id)->delay(now()->addMinute());
        }
    }

    public function failed(Throwable $exception): void
    {
        $service = Service::find($this->serviceId);

        if ($service) {
            $service->update([
                'focus_nfse_error' => [
                    'operation' => 'emissao',
                    'message' => 'Esgotadas as tentativas de emissão: '.$exception->getMessage(),
                    'exception' => $exception::class,
                    'at' => now()->toIso8601String(),
                ],
            ]);
        }
    }
}
