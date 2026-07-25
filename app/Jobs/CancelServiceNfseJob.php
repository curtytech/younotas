<?php

namespace App\Jobs;

use App\Actions\CancelServiceNfseAction;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CancelServiceNfseJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $uniqueFor = 600;

    public function __construct(public int $serviceId, public string $justification) {}

    public function uniqueId(): string
    {
        return (string) $this->serviceId;
    }

    public function handle(CancelServiceNfseAction $action): void
    {
        $action->execute(Service::findOrFail($this->serviceId), $this->justification);
    }

    public function failed(Throwable $exception): void
    {
        $service = Service::find($this->serviceId);

        if ($service) {
            $service->update([
                'focus_nfse_error' => [
                    'operation' => 'cancelamento',
                    'message' => 'Esgotadas as tentativas de cancelamento: '.$exception->getMessage(),
                    'exception' => $exception::class,
                    'at' => now()->toIso8601String(),
                ],
            ]);
        }
    }
}
