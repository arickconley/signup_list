<?php

use App\Support\ImmediateDatabaseTransaction;

test('message-only PDO exceptions are not retried as SQLite lock contention', function () {
    $attempts = 0;
    $exception = new PDOException('database is locked but carries no SQLite result code');

    $run = function () use (&$attempts, $exception): void {
        app(ImmediateDatabaseTransaction::class)->run(
            function () use (&$attempts, $exception): never {
                $attempts++;

                throw $exception;
            },
        );
    };

    expect($run)->toThrow($exception);

    expect($attempts)->toBe(1);
});
