<?php

use App\Exceptions\ImmediateTransactionBusy;
use App\Support\ImmediateDatabaseTransaction;
use Illuminate\Support\Facades\Log;

test('SQLite lock retries produce a structured warning', function () {
    Log::spy();
    $attempts = 0;

    $result = app(ImmediateDatabaseTransaction::class)->run(function () use (&$attempts): string {
        $attempts++;

        if ($attempts === 1) {
            $exception = new PDOException('database is locked');
            $exception->errorInfo = ['HY000', 5, 'database is locked'];

            throw $exception;
        }

        return 'completed';
    });

    expect($result)->toBe('completed');
    Log::shouldHaveReceived('warning')
        ->with('sqlite.lock_retry', [
            'attempt' => 1,
            'max_attempts' => 3,
            'exception' => PDOException::class,
            'error' => 'database is locked',
        ])
        ->once();
});

test('exhausted SQLite lock retries produce a structured error', function () {
    Log::spy();

    expect(fn () => app(ImmediateDatabaseTransaction::class)->run(function (): never {
        $exception = new PDOException('database is locked');
        $exception->errorInfo = ['HY000', 5, 'database is locked'];

        throw $exception;
    }))->toThrow(ImmediateTransactionBusy::class);

    Log::shouldHaveReceived('error')
        ->with('sqlite.lock_failed', [
            'attempts' => 3,
            'exception' => PDOException::class,
            'error' => 'database is locked',
        ])
        ->once();
});
