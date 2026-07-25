<?php

namespace App\Jobs;

use App\Actions\ConsultSaleNfeAction;
use App\Models\Sale;
use App\Support\NfeStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ConsultSaleNfeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public array $backoff = [60, 120, 300, 600];

    public int $uniqueFor = 7200;

    public function __construct(public int $saleId) {}

    public function uniqueId(): string
    {
        return (string) $this->saleId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('focus-nfe-consult:'.$this->saleId))->releaseAfter(60)->expireAfter(7200)];
    }

    public function handle(ConsultSaleNfeAction $action): void
    {
        $action->execute(Sale::findOrFail($this->saleId));
        $sale = Sale::find($this->saleId);
        if ($sale?->focus_nfe_status === NfeStatus::PROCESSING) {
            $this->release(300);
        }
    }

    public function failed(Throwable $exception): void
    {
        Sale::find($this->saleId)?->update(['focus_nfe_error' => [
            'operation' => 'consulta', 'message' => 'Esgotadas as tentativas: '.$exception->getMessage(),
            'exception' => $exception::class, 'at' => now()->toIso8601String(),
        ]]);
    }
}
