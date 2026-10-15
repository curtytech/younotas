<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\FocusNfeBackupService;
use App\Services\FiscalEmissionPreparation;
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
    public int $connectionId;
    public ?int $actorId;

    public function __construct(public int $userId, public string $month, ?int $connectionId = null)
    {
        $config = app(FocusNfeBackupService::class)->configurationFor(User::findOrFail($userId), $connectionId);
        $this->connectionId = $config['fiscal_connection_id'];
        $this->actorId = auth()->id();
        app(FiscalEmissionPreparation::class)->authorize($config['company_id'], $this->actorId);
    }

    public function uniqueId(): string
    {
        return $this->connectionId.':'.$this->month;
    }

    public function handle(FocusNfeBackupService $service): void
    {
        $user = User::findOrFail($this->userId);
        $config = $service->configurationFor($user, $this->connectionId);
        app(FiscalEmissionPreparation::class)->authorize($config['company_id'], $this->actorId);
        $service->importMonth($user, $this->month, $this->connectionId);
    }

    public function failed(Throwable $exception): void
    {
        logger()->error('Falha ao importar documento fiscal da Focus.', [
            'user_id' => $this->userId,
            'fiscal_connection_id' => $this->connectionId,
            'exception' => $exception::class,
        ]);
    }
}
