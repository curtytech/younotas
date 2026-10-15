<?php

namespace App\Jobs;

use App\Actions\ConsultServiceNfseAction;
use App\Models\ServiceOrder;
use App\Support\NfseStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ConsultServiceNfseJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public array $backoff = [60, 120, 300, 600];

    public int $uniqueFor = 7200;

    public function __construct(public int $serviceId) {}

    public function uniqueId(): string
    {
        return (string) $this->serviceId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('focus-nfse-consult:'.$this->serviceId))->releaseAfter(60)->expireAfter(7200)];
    }

    public function handle(ConsultServiceNfseAction $action): void
    {
        $service = ServiceOrder::findOrFail($this->serviceId);
        $action->execute($service);
        $service->refresh();

        if ($service->focus_nfse_status === NfseStatus::PROCESSING) {
            $this->release(300);
        }
    }

    public function failed(Throwable $exception): void
    {
        $service = ServiceOrder::find($this->serviceId);

        if ($service) {
            $service->update([
                'focus_nfse_error' => [
                    'operation' => 'consulta',
                    'message' => 'Esgotadas as tentativas de consulta: '.$exception->getMessage(),
                    'exception' => $exception::class,
                    'at' => now()->toIso8601String(),
                ],
            ]);
        }
    }
}
