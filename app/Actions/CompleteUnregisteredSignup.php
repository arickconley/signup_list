<?php

namespace App\Actions;

use App\Exceptions\CannotCompleteSignup;
use App\Models\Option;
use App\Models\Sheet;
use App\Models\Signup;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Throwable;

class CompleteUnregisteredSignup
{
    private const int LOCK_ATTEMPTS = 3;

    /**
     * @param  array<int, string>  $optionPublicIds
     */
    public function handle(
        string $sheetPublicId,
        string $name,
        ?string $phone,
        array $optionPublicIds,
    ): Signup {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            throw new CannotCompleteSignup('Signups are temporarily unavailable. Please try again.');
        }

        return $this->immediateTransaction(
            $connection,
            fn (): Signup => $this->createSignup(
                $sheetPublicId,
                $name,
                $phone,
                $optionPublicIds,
            ),
        );
    }

    /**
     * @param  array<int, string>  $optionPublicIds
     */
    private function createSignup(
        string $sheetPublicId,
        string $name,
        ?string $phone,
        array $optionPublicIds,
    ): Signup {
        $sheet = Sheet::query()->where('public_id', $sheetPublicId)->first();

        if (
            $sheet === null
            || $sheet->state !== Sheet::STATE_PUBLISHED
            || $sheet->participation_policy !== Sheet::PARTICIPATION_OPEN
            || ! $sheet->deadline_at->isFuture()
        ) {
            throw new CannotCompleteSignup('This Signup Sheet is no longer open for signups.');
        }

        $selectionMaximum = $sheet->selection_maximum;
        $uniqueOptionPublicIds = array_values(array_unique($optionPublicIds));

        if (
            $selectionMaximum === null
            || count($uniqueOptionPublicIds) !== count($optionPublicIds)
            || count($optionPublicIds) < 1
            || count($optionPublicIds) > $selectionMaximum
        ) {
            throw new CannotCompleteSignup(
                "Choose between 1 and {$selectionMaximum} available Options.",
            );
        }

        $options = Option::query()
            ->where('sheet_id', $sheet->id)
            ->whereIn('public_id', $optionPublicIds)
            ->orderBy('id')
            ->get();

        if ($options->count() !== count($optionPublicIds)) {
            throw new CannotCompleteSignup('One or more selected Options do not belong to this Signup Sheet.');
        }

        $unavailableOptions = $options->filter(
            fn (Option $option): bool => $option->claimed_count >= $option->capacity,
        );

        if ($unavailableOptions->isNotEmpty()) {
            throw new CannotCompleteSignup(
                'Some selected Options just became unavailable. Choose another Option and try again.',
                $unavailableOptions->pluck('name')->all(),
                $unavailableOptions->pluck('public_id')->all(),
            );
        }

        $signup = $sheet->signups()->create([
            'name_snapshot' => $name,
            'phone_snapshot' => $phone,
        ]);

        foreach ($options as $option) {
            $updated = Option::query()
                ->whereKey($option->id)
                ->whereColumn('claimed_count', '<', 'capacity')
                ->increment('claimed_count');

            if ($updated !== 1) {
                throw new CannotCompleteSignup(
                    'Some selected Options just became unavailable. Choose another Option and try again.',
                    [$option->name],
                    [$option->public_id],
                );
            }

            $signup->optionClaims()->create(['option_id' => $option->id]);
        }

        return $signup;
    }

    /** @param  Closure(): Signup  $callback */
    private function immediateTransaction(Connection $connection, Closure $callback): Signup
    {
        if ($connection->transactionLevel() > 0) {
            return $connection->transaction(
                fn (Connection $_connection): Signup => $callback(),
            );
        }

        $pdo = $connection->getPdo();

        for ($attempt = 1; $attempt <= self::LOCK_ATTEMPTS; $attempt++) {
            $transactionStarted = false;

            try {
                $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
                $transactionStarted = true;
                $signup = $callback();
                $pdo->exec('COMMIT');

                return $signup;
            } catch (Throwable $exception) {
                if ($transactionStarted) {
                    try {
                        $pdo->exec('ROLLBACK');
                    } catch (Throwable) {
                        // Preserve the exception that caused the transaction to fail.
                    }
                }

                if (! $this->isLockContention($exception)) {
                    throw $exception;
                }

                if ($attempt === self::LOCK_ATTEMPTS) {
                    throw new CannotCompleteSignup(
                        'The signup list is busy. Please wait a moment and try again.',
                        previous: $exception,
                    );
                }

                usleep($attempt * 25_000);
            }
        }

        throw new CannotCompleteSignup('The signup list is busy. Please try again.');
    }

    private function isLockContention(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (! $current instanceof PDOException) {
                continue;
            }

            $sqliteCode = isset($current->errorInfo[1]) ? (int) $current->errorInfo[1] : null;

            if ($sqliteCode !== null) {
                return in_array($sqliteCode & 0xFF, [5, 6], true);
            }

            if (Str::contains(Str::lower($current->getMessage()), [
                'database is locked',
                'database table is locked',
                'database file is locked',
            ])) {
                return true;
            }
        }

        return false;
    }
}
