<?php

use App\Actions\DeleteAccount;
use App\Data\AccountDeletionSummary;
use App\Exceptions\CannotDeleteAccount;
use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;

test('busy Account deletion is bounded recoverable and atomic', function () {
    Mail::fake();

    $originalDefaultConnection = config('database.default');
    $originalDatabase = config('database.connections.sqlite.database');
    $originalBusyTimeout = config('database.connections.sqlite.busy_timeout');
    $originalTransactionMode = config('database.connections.sqlite.transaction_mode');
    $databasePath = tempnam(sys_get_temp_dir(), 'account-deletion-busy-');

    expect($databasePath)->toBeString();

    $readyPath = $databasePath.'.ready';
    $lockProcess = null;

    try {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', $databasePath);
        config()->set('database.connections.sqlite.busy_timeout', 100);
        config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $account = Account::factory()->create();
        $owner = Account::factory()->create();
        $ownedSheet = Sheet::factory()->for($account, 'owner')->create();
        $foreignSheet = Sheet::factory()->for($owner, 'owner')->create();
        $foreignSignup = $foreignSheet->signups()->create([
            'name_snapshot' => 'Concurrent identity',
            'email_snapshot' => $account->email,
            'phone_snapshot' => '555-0170',
        ]);
        $foreignSignup->forceFill(['account_id' => $account->id])->save();
        $confirmedSummary = AccountDeletionSummary::for($account);

        DB::disconnect('sqlite');

        $lockProcess = new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/hold-sqlite-write-lock.php'),
            $databasePath,
            $readyPath,
            '1000',
        ]);
        $lockProcess->setTimeout(10)->start();

        $readyDeadline = microtime(true) + 5;

        while (! file_exists($readyPath) && $lockProcess->isRunning() && microtime(true) < $readyDeadline) {
            usleep(5_000);
        }

        expect(file_exists($readyPath))->toBeTrue();

        DB::reconnect('sqlite');
        $startedAt = microtime(true);
        $failure = null;

        try {
            app(DeleteAccount::class)->handle($account, $confirmedSummary);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $elapsedMilliseconds = (microtime(true) - $startedAt) * 1000;

        expect($elapsedMilliseconds)->toBeLessThan(800)
            ->and($failure)->toBeInstanceOf(CannotDeleteAccount::class)
            ->getMessage()->toBe('Account deletion is temporarily unavailable. Please wait a moment and try again.')
            ->and($account->fresh())->not->toBeNull()
            ->and($ownedSheet->fresh())->not->toBeNull()
            ->and($foreignSignup->refresh())
            ->account_id->toBe($account->id)
            ->name_snapshot->toBe('Concurrent identity')
            ->email_snapshot->toBe($account->email)
            ->phone_snapshot->toBe('555-0170');
        Mail::assertNothingQueued();

        $lockProcess->wait();
        expect($lockProcess->isSuccessful())->toBeTrue();

        DB::disconnect('sqlite');
        DB::reconnect('sqlite');

        app(DeleteAccount::class)->handle($account, AccountDeletionSummary::for($account));

        expect($account->fresh())->toBeNull()
            ->and($ownedSheet->fresh())->toBeNull()
            ->and($foreignSignup->refresh())
            ->account_id->toBeNull()
            ->name_snapshot->toBe('Deleted participant')
            ->email_snapshot->toBeNull()
            ->phone_snapshot->toBeNull();
        Mail::assertNothingQueued();
    } finally {
        if ($lockProcess?->isRunning()) {
            $lockProcess->stop();
        }

        DB::purge('sqlite');
        config()->set([
            'database.default' => $originalDefaultConnection,
            'database.connections.sqlite.database' => $originalDatabase,
            'database.connections.sqlite.busy_timeout' => $originalBusyTimeout,
            'database.connections.sqlite.transaction_mode' => $originalTransactionMode,
        ]);
        DB::reconnect('sqlite');

        @unlink($readyPath);
        @unlink($databasePath);
        @unlink($databasePath.'-shm');
        @unlink($databasePath.'-wal');
        @unlink($databasePath.'-journal');
    }
});
