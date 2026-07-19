<?php

use App\Exceptions\ImmediateTransactionBusy;
use App\Models\AccountAccessChallenge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

test('busy operational cleanup retries within a bound and remains rerunnable', function () {
    $originalDefaultConnection = config('database.default');
    $originalDatabase = config('database.connections.sqlite.database');
    $originalBusyTimeout = config('database.connections.sqlite.busy_timeout');
    $originalTransactionMode = config('database.connections.sqlite.transaction_mode');
    $databasePath = tempnam(sys_get_temp_dir(), 'operational-cleanup-busy-');

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

        $expiredChallenge = AccountAccessChallenge::query()->create([
            'public_id' => (string) Str::uuid(),
            'email' => 'expired@example.test',
            'code_hash' => 'expired-code',
            'token_hash' => 'expired-token',
            'expires_at' => now()->subSecond(),
        ]);

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
        Log::spy();
        $startedAt = microtime(true);
        $failure = null;

        try {
            $this->artisan('app:cleanup-expired')->run();
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $elapsedMilliseconds = (microtime(true) - $startedAt) * 1000;

        expect($elapsedMilliseconds)->toBeLessThan(800)
            ->and($failure)->toBeInstanceOf(ImmediateTransactionBusy::class)
            ->and($expiredChallenge->fresh())->not->toBeNull();
        Log::shouldHaveReceived('warning')->twice();
        Log::shouldHaveReceived('error')
            ->with('sqlite.lock_failed', Mockery::type('array'))
            ->once();

        $lockProcess->wait();
        expect($lockProcess->isSuccessful())->toBeTrue();

        DB::disconnect('sqlite');
        DB::reconnect('sqlite');

        $this->artisan('app:cleanup-expired')->assertSuccessful();

        expect($expiredChallenge->fresh())->toBeNull();
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
