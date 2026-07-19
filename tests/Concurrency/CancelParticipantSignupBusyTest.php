<?php

use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

test('busy cancellation leaves the Signup claims and every capacity counter unchanged', function () {
    $originalDatabase = config('database.connections.sqlite.database');
    $originalBusyTimeout = config('database.connections.sqlite.busy_timeout');
    $originalTransactionMode = config('database.connections.sqlite.transaction_mode');

    $databasePath = tempnam(sys_get_temp_dir(), 'signup-cancel-busy-');

    expect($databasePath)->toBeString();

    $gatePath = $databasePath.'.go';
    $cancelReadyPath = $databasePath.'.cancel-ready';
    $lockReadyPath = $databasePath.'.lock-ready';
    $processes = [];

    try {
        config()->set('database.connections.sqlite.database', $databasePath);
        config()->set('database.connections.sqlite.busy_timeout', 100);
        config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $account = Account::factory()->create();
        $sheet = Sheet::factory()->create([
            'state' => Sheet::STATE_PUBLISHED,
            'participation_policy' => Sheet::PARTICIPATION_OPEN,
            'selection_maximum' => 2,
        ]);
        $signup = $sheet->signups()->create([
            'name_snapshot' => 'Busy Cancellation',
            'email_snapshot' => $account->email,
        ]);
        $signup->forceFill(['account_id' => $account->id])->save();

        $first = $sheet->options()->create([
            'name' => 'First claim',
            'capacity' => 2,
            'claimed_count' => 1,
            'position' => 1,
        ]);
        $second = $sheet->options()->create([
            'name' => 'Second claim',
            'capacity' => 2,
            'claimed_count' => 1,
            'position' => 2,
        ]);
        $signup->optionClaims()->createMany([
            ['option_id' => $first->id],
            ['option_id' => $second->id],
        ]);

        DB::disconnect('sqlite');

        $cancellation = new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/cancel-participant-signup.php'),
            $databasePath,
            (string) $account->id,
            (string) $signup->id,
            $cancelReadyPath,
            $gatePath,
        ], base_path());
        $cancellation->setTimeout(10)->start();
        $processes[] = $cancellation;

        $cancelReadyDeadline = microtime(true) + 5;

        while (
            ! file_exists($cancelReadyPath)
            && $cancellation->isRunning()
            && microtime(true) < $cancelReadyDeadline
        ) {
            usleep(5_000);
        }

        expect(file_exists($cancelReadyPath))->toBeTrue();

        $lockHolder = new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/hold-sqlite-write-lock.php'),
            $databasePath,
            $lockReadyPath,
            '1200',
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
        $cancellation->wait();
        $lockHolder->wait();

        $result = json_decode(
            trim($cancellation->getOutput()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        DB::reconnect('sqlite');

        expect(array_unique([
            (int) $result['pid'],
            (int) file_get_contents($lockReadyPath),
        ]))->toHaveCount(2)
            ->and($result['result'])->toBe('busy')
            ->and($result['busy_timeout'])->toBe(100)
            ->and($result['action_elapsed_ms'])->toBeLessThan(800)
            ->and(Signup::query()->find($signup->id))->not->toBeNull()
            ->and(OptionClaim::query()->where('signup_id', $signup->id)->count())->toBe(2)
            ->and($first->fresh()->claimed_count)->toBe(1)
            ->and($second->fresh()->claimed_count)->toBe(1);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }

        DB::purge('sqlite');

        config()->set([
            'database.connections.sqlite.database' => $originalDatabase,
            'database.connections.sqlite.busy_timeout' => $originalBusyTimeout,
            'database.connections.sqlite.transaction_mode' => $originalTransactionMode,
        ]);

        DB::reconnect('sqlite');

        @unlink($gatePath);
        @unlink($cancelReadyPath);
        @unlink($lockReadyPath);
        @unlink($databasePath);
        @unlink($databasePath.'-shm');
        @unlink($databasePath.'-wal');
    }
});
