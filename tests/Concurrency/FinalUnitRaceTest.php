<?php

use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

test('separate processes competing for the final unit produce exactly one Signup and Option Claim', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'signup-final-unit-');

    expect($databasePath)->toBeString();

    $gatePath = $databasePath.'.go';
    $firstReadyPath = $databasePath.'.first-ready';
    $secondReadyPath = $databasePath.'.second-ready';
    $processes = [];

    try {
        config()->set('database.connections.sqlite.database', $databasePath);
        config()->set('database.connections.sqlite.busy_timeout', 100);
        config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $sheet = Sheet::factory()->create([
            'state' => Sheet::STATE_PUBLISHED,
            'participation_policy' => Sheet::PARTICIPATION_OPEN,
            'selection_maximum' => 1,
        ]);
        $option = $sheet->options()->create([
            'name' => 'Final unit',
            'capacity' => 1,
            'position' => 1,
        ]);
        DB::disconnect('sqlite');

        $fixture = base_path('tests/Fixtures/complete-unregistered-signup.php');
        $processes = [
            new Process([
                PHP_BINARY,
                $fixture,
                $databasePath,
                $sheet->public_id,
                $option->public_id,
                'First Participant',
                $firstReadyPath,
                $gatePath,
            ], base_path()),
            new Process([
                PHP_BINARY,
                $fixture,
                $databasePath,
                $sheet->public_id,
                $option->public_id,
                'Second Participant',
                $secondReadyPath,
                $gatePath,
            ], base_path()),
        ];

        foreach ($processes as $process) {
            $process->setTimeout(10)->start();
        }

        $readyDeadline = microtime(true) + 5;

        while (
            (! file_exists($firstReadyPath) || ! file_exists($secondReadyPath))
            && ($processes[0]->isRunning() || $processes[1]->isRunning())
            && microtime(true) < $readyDeadline
        ) {
            usleep(10_000);
        }

        expect(file_exists($firstReadyPath))->toBeTrue()
            ->and(file_exists($secondReadyPath))->toBeTrue();

        touch($gatePath);

        foreach ($processes as $process) {
            $process->wait();
        }

        $results = array_map(
            fn (Process $process): array => json_decode(
                trim($process->getOutput()),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            $processes,
        );

        $resultCounts = array_count_values(array_column($results, 'result'));

        expect(array_unique(array_column($results, 'pid')))->toHaveCount(2)
            ->and(array_unique(array_column($results, 'busy_timeout')))->toBe([100])
            ->and($resultCounts['success'] ?? 0)->toBe(1)
            ->and($resultCounts['unavailable'] ?? 0)->toBe(1);

        DB::reconnect('sqlite');

        expect(Signup::query()->count())->toBe(1)
            ->and(OptionClaim::query()->count())->toBe(1)
            ->and($option->fresh()->claimed_count)->toBe(1);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }

        DB::purge('sqlite');
        @unlink($gatePath);
        @unlink($firstReadyPath);
        @unlink($secondReadyPath);
        @unlink($databasePath);
        @unlink($databasePath.'-shm');
        @unlink($databasePath.'-wal');
    }
})->repeat(10);
