<?php

use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

test('SQLite lock retries succeed after release and stop at the configured bound', function () {
    $runScenario = function (int $holdMilliseconds): array {
        $databasePath = tempnam(sys_get_temp_dir(), 'signup-lock-retry-');

        expect($databasePath)->toBeString();

        $gatePath = $databasePath.'.go';
        $completionReadyPath = $databasePath.'.completion-ready';
        $lockReadyPath = $databasePath.'.lock-ready';
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
                'name' => 'Lock retry Option',
                'capacity' => 1,
                'position' => 1,
            ]);
            DB::disconnect('sqlite');

            $completion = new Process([
                PHP_BINARY,
                base_path('tests/Fixtures/complete-open-signup.php'),
                $databasePath,
                $sheet->public_id,
                $option->public_id,
                'Lock Retry Participant',
                $completionReadyPath,
                $gatePath,
            ], base_path());
            $completion->setTimeout(10)->start();
            $processes[] = $completion;

            $completionReadyDeadline = microtime(true) + 5;

            while (
                ! file_exists($completionReadyPath)
                && $completion->isRunning()
                && microtime(true) < $completionReadyDeadline
            ) {
                usleep(5_000);
            }

            expect(file_exists($completionReadyPath))->toBeTrue();

            $lockHolder = new Process([
                PHP_BINARY,
                base_path('tests/Fixtures/hold-sqlite-write-lock.php'),
                $databasePath,
                $lockReadyPath,
                (string) $holdMilliseconds,
            ], base_path());
            $lockHolder->setTimeout(10)->start();
            $processes[] = $lockHolder;

            $lockReadyDeadline = microtime(true) + 5;

            while (
                ! file_exists($lockReadyPath)
                && $lockHolder->isRunning()
                && microtime(true) < $lockReadyDeadline
            ) {
                usleep(5_000);
            }

            expect(file_exists($lockReadyPath))->toBeTrue();

            touch($gatePath);
            $completion->wait();
            $lockHolder->wait();

            $result = json_decode(
                trim($completion->getOutput()),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            DB::reconnect('sqlite');

            return [
                ...$result,
                'signup_count' => Signup::query()->count(),
                'claim_count' => OptionClaim::query()->count(),
                'claimed_count' => $option->fresh()->claimed_count,
            ];
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }

            DB::purge('sqlite');
            @unlink($gatePath);
            @unlink($completionReadyPath);
            @unlink($lockReadyPath);
            @unlink($databasePath);
            @unlink($databasePath.'-shm');
            @unlink($databasePath.'-wal');
        }
    };

    $releasedDuringRetries = $runScenario(180);

    expect($releasedDuringRetries['result'])->toBe('success')
        ->and($releasedDuringRetries['busy_timeout'])->toBe(100)
        ->and($releasedDuringRetries['action_elapsed_ms'])->toBeGreaterThan(100)
        ->and($releasedDuringRetries['signup_count'])->toBe(1)
        ->and($releasedDuringRetries['claim_count'])->toBe(1)
        ->and($releasedDuringRetries['claimed_count'])->toBe(1);

    $heldPastBound = $runScenario(1200);

    expect($heldPastBound['result'])->toBe('busy')
        ->and($heldPastBound['busy_timeout'])->toBe(100)
        ->and($heldPastBound['action_elapsed_ms'])->toBeLessThan(800)
        ->and($heldPastBound['signup_count'])->toBe(0)
        ->and($heldPastBound['claim_count'])->toBe(0)
        ->and($heldPastBound['claimed_count'])->toBe(0);
});

test('arbitrary SQLite failures are not retried even when their message says database is locked', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'signup-non-lock-error-');

    expect($databasePath)->toBeString();

    $gatePath = $databasePath.'.go';
    $readyPath = $databasePath.'.ready';
    $attemptCounterPath = $databasePath.'.attempts';
    $completion = null;

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
            'name' => 'Trigger failure Option',
            'capacity' => 1,
            'position' => 1,
        ]);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reject_signup
            BEFORE INSERT ON signups
            BEGIN
                SELECT record_signup_attempt();
                SELECT RAISE(ABORT, 'database is locked but this is an arbitrary constraint failure');
            END
            SQL);
        DB::disconnect('sqlite');
        touch($gatePath);

        $completion = new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/complete-open-signup.php'),
            $databasePath,
            $sheet->public_id,
            $option->public_id,
            'Non-lock Failure Participant',
            $readyPath,
            $gatePath,
            $attemptCounterPath,
        ], base_path());
        $completion->setTimeout(10)->run();

        $result = json_decode(
            trim($completion->getOutput()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        DB::reconnect('sqlite');

        expect($result['result'])->toBe('unexpected-error')
            ->and(file_get_contents($attemptCounterPath))->toBe('1')
            ->and(Signup::query()->count())->toBe(0)
            ->and(OptionClaim::query()->count())->toBe(0)
            ->and($option->fresh()->claimed_count)->toBe(0);
    } finally {
        if ($completion?->isRunning()) {
            $completion->stop();
        }

        DB::purge('sqlite');
        @unlink($gatePath);
        @unlink($readyPath);
        @unlink($attemptCounterPath);
        @unlink($databasePath);
        @unlink($databasePath.'-shm');
        @unlink($databasePath.'-wal');
    }
});
