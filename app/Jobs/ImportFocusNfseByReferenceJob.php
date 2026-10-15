<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\FocusNfseImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ImportFocusNfseByReferenceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $uniqueFor = 3600;

    public function __construct(
        public int $userId,
        public string $reference,
    ) {}

    public function uniqueId(): string
    {
        return $this->userId.':'.$this->reference;
    }

    public function handle(FocusNfseImportService $service): void
    {
        $service->importByReference(User::findOrFail($this->userId), $this->reference);
    }

    public function failed(Throwable $exception): void
    {
        logger()->error('Falha ao importar NFS-e da Focus por referência.', [
            'user_id' => $this->userId,
            'reference' => $this->reference,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
