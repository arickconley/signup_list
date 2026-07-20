<?php

use App\Models\Account;
use App\Models\PendingAccountAssociation;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

test('Verified Participation mount stays accessible without a pending candidate during SQLite write contention', function () {
    $originalDefaultConnection = config('database.default');
    $originalDatabase = config('database.connections.sqlite.database');
    $originalBusyTimeout = config('database.connections.sqlite.busy_timeout');
    $originalTransactionMode = config('database.connections.sqlite.transaction_mode');
    $databasePath = tempnam(sys_get_temp_dir(), 'signup-verified-mount-no-candidate-');

    expect($databasePath)->toBeString();

    $lockReadyPath = $databasePath.'.lock-ready';
    $lockHolder = null;

    try {
        config()->set([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $databasePath,
            'database.connections.sqlite.busy_timeout' => 100,
            'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
        ]);
        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $account = Account::factory()->create([
            'email' => 'mount-no-candidate@example.com',
        ]);
        $sheet = Sheet::factory()->create([
            'state' => Sheet::STATE_PUBLISHED,
            'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
            'selection_maximum' => 1,
        ]);
        $option = $sheet->options()->create([
            'name' => 'Available despite unrelated write',
            'capacity' => 1,
            'position' => 1,
        ]);

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

        expect($lockReadyPath)->toBeFile();

        $this->actingAs($account)
            ->get(route('sheets.participate', $sheet))
            ->assertOk()
            ->assertSee('Claim')
            ->assertSee($option->name);

        expect(Signup::query()->count())->toBe(0)
            ->and(PendingAccountAssociation::query()->count())->toBe(0)
            ->and($option->fresh()->claimed_count)->toBe(0);
    } finally {
        if ($lockHolder?->isRunning()) {
            $lockHolder->stop();
        }

        DB::purge('sqlite');
        config()->set([
            'database.default' => $originalDefaultConnection,
            'database.connections.sqlite.database' => $originalDatabase,
            'database.connections.sqlite.busy_timeout' => $originalBusyTimeout,
            'database.connections.sqlite.transaction_mode' => $originalTransactionMode,
        ]);
        DB::reconnect('sqlite');

        @unlink($lockReadyPath);
        @unlink($databasePath);
        @unlink($databasePath.'-journal');
        @unlink($databasePath.'-shm');
        @unlink($databasePath.'-wal');
    }
});

test('Verified Participation mount reports recoverable contention without changing a pending candidate', function () {
    $originalDefaultConnection = config('database.default');
    $originalDatabase = config('database.connections.sqlite.database');
    $originalBusyTimeout = config('database.connections.sqlite.busy_timeout');
    $originalTransactionMode = config('database.connections.sqlite.transaction_mode');
    $databasePath = tempnam(sys_get_temp_dir(), 'signup-verified-mount-candidate-');

    expect($databasePath)->toBeString();

    $lockReadyPath = $databasePath.'.lock-ready';
    $lockHolder = null;

    try {
        config()->set([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $databasePath,
            'database.connections.sqlite.busy_timeout' => 100,
            'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
        ]);
        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $account = Account::factory()->create([
            'email' => 'mount-candidate@example.com',
        ]);
        $sheet = Sheet::factory()->create([
            'state' => Sheet::STATE_PUBLISHED,
            'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
            'selection_maximum' => 1,
        ]);
        $option = $sheet->options()->create([
            'name' => 'Preserved candidate claim',
            'capacity' => 2,
            'claimed_count' => 1,
            'position' => 1,
        ]);
        $signup = $sheet->signups()->create([
            'name_snapshot' => 'Preserved Candidate',
            'email_snapshot' => $account->email,
            'phone_snapshot' => '555-0199',
            'name_consent' => true,
            'email_consent' => false,
            'phone_consent' => true,
        ]);
        $association = $signup->pendingAccountAssociation()->create([
            'account_id' => $account->id,
        ]);
        $claim = $signup->optionClaims()->create(['option_id' => $option->id]);
        $signupSnapshot = $signup->only([
            'id',
            'sheet_id',
            'account_id',
            'name_snapshot',
            'email_snapshot',
            'phone_snapshot',
            'name_consent',
            'email_consent',
            'phone_consent',
        ]);

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

        expect($lockReadyPath)->toBeFile();

        $this->actingAs($account)
            ->get(route('sheets.participate', $sheet))
            ->assertOk()
            ->assertSeeTextInOrder([
                'The Signup Sheet is busy. Please wait a moment and try again.',
                'The Signup Sheet is busy. Please wait a moment and try again.',
            ])
            ->assertSee('Claim');

        expect(Signup::query()->count())->toBe(1)
            ->and($signup->fresh()->only(array_keys($signupSnapshot)))->toBe($signupSnapshot)
            ->and(PendingAccountAssociation::query()->find($association->id))->not->toBeNull()
            ->and($signup->optionClaims()->pluck('id')->all())->toBe([$claim->id])
            ->and($option->fresh()->claimed_count)->toBe(1);
    } finally {
        if ($lockHolder?->isRunning()) {
            $lockHolder->stop();
        }

        DB::purge('sqlite');
        config()->set([
            'database.default' => $originalDefaultConnection,
            'database.connections.sqlite.database' => $originalDatabase,
            'database.connections.sqlite.busy_timeout' => $originalBusyTimeout,
            'database.connections.sqlite.transaction_mode' => $originalTransactionMode,
        ]);
        DB::reconnect('sqlite');

        @unlink($lockReadyPath);
        @unlink($databasePath);
        @unlink($databasePath.'-journal');
        @unlink($databasePath.'-shm');
        @unlink($databasePath.'-wal');
    }
});
