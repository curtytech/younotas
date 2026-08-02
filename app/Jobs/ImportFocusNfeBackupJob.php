<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\FocusNfeBackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ImportFocusNfeBackupJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $uniqueFor = 3600;

    public function __construct(
        public int $userId,
        public string $month,
    ) {}

    public function uniqueId(): string
    {
        return $this->userId.':'.$this->month;
    }

    public function handle(FocusNfeBackupService $service): void
    {
        $service->importMonth(User::findOrFail($this->userId), $this->month);
    }

    public function failed(Throwable $exception): void
    {
        logger()->error('Falha ao importar backup de NF-e da Focus.', [
            'user_id' => $this->userId,
            'month' => $this->month,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
