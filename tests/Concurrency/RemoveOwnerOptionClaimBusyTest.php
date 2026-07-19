<?php

use App\Actions\RemoveOwnerOptionClaim;
use App\Exceptions\CannotRemoveOwnerOptionClaim;
use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;

test('busy Owner Option Claim removal is bounded atomic and recoverable', function () {
    Mail::fake();

    $originalDatabase = config('database.connections.sqlite.database');
    $originalBusyTimeout = config('database.connections.sqlite.busy_timeout');
    $originalTransactionMode = config('database.connections.sqlite.transaction_mode');
    $databasePath = tempnam(sys_get_temp_dir(), 'owner-claim-removal-busy-');

    expect($databasePath)->toBeString();

    $lockReadyPath = $databasePath.'.lock-ready';
    $lockHolder = null;

    try {
        config()->set('database.connections.sqlite.database', $databasePath);
        config()->set('database.connections.sqlite.busy_timeout', 100);
        config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $owner = Account::factory()->create();
        $sheet = Sheet::factory()->for($owner, 'owner')->create();
        $option = $sheet->options()->create([
            'name' => 'Busy claim',
            'capacity' => 2,
            'claimed_count' => 1,
            'position' => 1,
        ]);
        $signup = $sheet->signups()->create(['name_snapshot' => 'Busy Participant']);
        $claim = $signup->optionClaims()->create(['option_id' => $option->id]);

        DB::disconnect('sqlite');

        $lockHolder = new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/hold-sqlite-write-lock.php'),
            $databasePath,
            $lockReadyPath,
            '1200',
        ], base_path());
        $lockHolder->setTimeout(10)->start();

        $lockReadyDeadline = microtime(true) + 5;

        while (
            ! file_exists($lockReadyPath)
            && $lockHolder->isRunning()
            && microtime(true) < $lockReadyDeadline
        ) {
            usleep(5_000);
        }

        expect(file_exists($lockReadyPath))->toBeTrue();

        DB::reconnect('sqlite');
        $startedAt = microtime(true);

        expect(fn () => app(RemoveOwnerOptionClaim::class)->handle($owner, $sheet, $claim->id))
            ->toThrow(
                CannotRemoveOwnerOptionClaim::class,
                'The Signup Sheet is busy. Please wait a moment and try again.',
            );

        $elapsedMilliseconds = (microtime(true) - $startedAt) * 1000;

        expect($elapsedMilliseconds)->toBeLessThan(800)
            ->and(OptionClaim::query()->find($claim->id))->not->toBeNull()
            ->and($option->fresh()->claimed_count)->toBe(1);
        Mail::assertNothingQueued();

        $lockHolder->wait();

        app(RemoveOwnerOptionClaim::class)->handle($owner, $sheet, $claim->id);

        expect(OptionClaim::query()->find($claim->id))->toBeNull()
            ->and($option->fresh()->claimed_count)->toBe(0);
        Mail::assertNothingQueued();
    } finally {
        if ($lockHolder?->isRunning()) {
            $lockHolder->stop();
        }

        DB::purge('sqlite');

        config()->set([
            'database.connections.sqlite.database' => $originalDatabase,
            'database.connections.sqlite.busy_timeout' => $originalBusyTimeout,
            'database.connections.sqlite.transaction_mode' => $originalTransactionMode,
        ]);

        DB::reconnect('sqlite');

        @unlink($lockReadyPath);
        @unlink($databasePath);
        @unlink($databasePath.'-shm');
        @unlink($databasePath.'-wal');
        @unlink($databasePath.'-journal');
    }
});
