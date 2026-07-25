<?php

namespace App\Jobs;

use App\Actions\EmitSaleNfeAction;
use App\Models\Sale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class EmitSaleNfeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $uniqueFor = 600;

    public function __construct(public int $saleId) {}

    public function uniqueId(): string
    {
        return (string) $this->saleId;
    }

    public function handle(EmitSaleNfeAction $action): void
    {
        $action->execute(Sale::findOrFail($this->saleId));
    }

    public function failed(Throwable $exception): void
    {
        Sale::find($this->saleId)?->update(['focus_nfe_error' => [
            'operation' => 'emissao', 'message' => 'Esgotadas as tentativas: '.$exception->getMessage(),
            'exception' => $exception::class, 'at' => now()->toIso8601String(),
        ]]);
    }
}
