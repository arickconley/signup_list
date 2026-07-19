<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'app:scheduler-heartbeat';

    protected $description = 'Atomically record evidence that the application scheduler is running';

    public function handle(): int
    {
        File::replace(
            (string) config('deployment.scheduler.heartbeat_path'),
            json_encode([
                'recorded_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR).PHP_EOL,
        );

        return self::SUCCESS;
    }
}
