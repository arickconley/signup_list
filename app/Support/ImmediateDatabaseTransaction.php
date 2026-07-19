<?php

namespace App\Support;

use App\Exceptions\ImmediateTransactionBusy;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use PDOException;
use Throwable;

final class ImmediateDatabaseTransaction
{
    private const int LOCK_ATTEMPTS = 3;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            throw new LogicException('Immediate database transactions require SQLite.');
        }

        if ($connection->transactionLevel() > 0) {
            return $connection->transaction(
                fn (Connection $_connection): mixed => $callback(),
            );
        }

        $pdo = $connection->getPdo();

        if ($pdo->inTransaction()) {
            return $callback();
        }

        for ($attempt = 1; $attempt <= self::LOCK_ATTEMPTS; $attempt++) {
            $transactionStarted = false;

            try {
                $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
                $transactionStarted = true;
                $result = $callback();
                $pdo->exec('COMMIT');

                return $result;
            } catch (Throwable $exception) {
                if ($transactionStarted) {
                    try {
                        $pdo->exec('ROLLBACK');
                    } catch (Throwable) {
                        // Preserve the exception that caused the transaction to fail.
                    }
                }

                if (! $this->isSqliteLockContention($exception)) {
                    throw $exception;
                }

                if ($attempt === self::LOCK_ATTEMPTS) {
                    Log::error('sqlite.lock_failed', [
                        'attempts' => self::LOCK_ATTEMPTS,
                        'exception' => $exception::class,
                        'error' => $exception->getMessage(),
                    ]);

                    throw new ImmediateTransactionBusy(
                        'SQLite immediate transaction remained busy after bounded retries.',
                        previous: $exception,
                    );
                }

                Log::warning('sqlite.lock_retry', [
                    'attempt' => $attempt,
                    'max_attempts' => self::LOCK_ATTEMPTS,
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);

                usleep($attempt * 25_000);
            }
        }

        throw new LogicException('Immediate transaction retry loop ended unexpectedly.');
    }

    private function isSqliteLockContention(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (! $current instanceof PDOException || ! isset($current->errorInfo[1])) {
                continue;
            }

            $sqliteResultCode = (int) $current->errorInfo[1];

            if (in_array($sqliteResultCode & 0xFF, [5, 6], true)) {
                return true;
            }
        }

        return false;
    }
}
