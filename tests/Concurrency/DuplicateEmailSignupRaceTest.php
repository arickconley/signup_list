<?php

use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\PendingAccountAssociation;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

test('separate same-email Signups claim capacity only once', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'signup-duplicate-email-');

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
            'name' => 'One email, one Option Claim',
            'capacity' => 2,
            'position' => 1,
        ]);
        DB::disconnect('sqlite');

        $fixture = base_path('tests/Fixtures/complete-email-signup.php');
        $processes = [
            new Process([
                PHP_BINARY,
                $fixture,
                $databasePath,
                $sheet->public_id,
                $option->public_id,
                'First Snapshot',
                'RACE@EXAMPLE.COM',
                $firstReadyPath,
                $gatePath,
            ], base_path()),
            new Process([
                PHP_BINARY,
                $fixture,
                $databasePath,
                $sheet->public_id,
                $option->public_id,
                'Second Snapshot',
                ' race@example.com ',
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

        expect(array_unique(array_column($results, 'pid')))->toHaveCount(2)
            ->and(array_column($results, 'result'))->each->toBe('success');

        DB::reconnect('sqlite');

        expect(Account::query()->where('email', 'race@example.com')->count())->toBe(1)
            ->and(Signup::query()->count())->toBe(1)
            ->and(Signup::query()->sole()->email_snapshot)->toBe('race@example.com')
            ->and(OptionClaim::query()->count())->toBe(1)
            ->and(PendingAccountAssociation::query()->count())->toBe(1)
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
})->repeat(5);
