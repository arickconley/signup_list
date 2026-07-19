<?php

use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * @param  array<int, Process>  $processes
 * @param  array<int, array{account_id: int, name: string, ready_path: string}>  $participants
 * @return array<int, array<string, mixed>>
 */
function runVerifiedParticipationRace(
    array &$processes,
    string $databasePath,
    string $sheetPublicId,
    string $optionPublicId,
    string $gatePath,
    array $participants,
): array {
    $fixture = base_path('tests/Fixtures/complete-verified-signup.php');

    foreach ($participants as $participant) {
        $process = new Process([
            PHP_BINARY,
            $fixture,
            $databasePath,
            $sheetPublicId,
            $optionPublicId,
            (string) $participant['account_id'],
            $participant['name'],
            $participant['ready_path'],
            $gatePath,
        ], base_path());
        $process->setTimeout(10)->start();
        $processes[] = $process;
    }

    $readyPaths = array_column($participants, 'ready_path');
    $readyDeadline = microtime(true) + 5;

    while (
        collect($readyPaths)->contains(fn (string $path): bool => ! file_exists($path))
        && collect($processes)->contains(fn (Process $process): bool => $process->isRunning())
        && microtime(true) < $readyDeadline
    ) {
        usleep(10_000);
    }

    expect($readyPaths)->each->toBeFile();

    touch($gatePath);

    foreach ($processes as $process) {
        $process->wait();
    }

    return array_map(
        fn (Process $process): array => json_decode(
            trim($process->getOutput()),
            true,
            flags: JSON_THROW_ON_ERROR,
        ),
        $processes,
    );
}

test('separate Verified attempts create one Signup per Account and Sheet', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'signup-verified-duplicate-');

    expect($databasePath)->toBeString();

    $gatePath = $databasePath.'.go';
    $readyPaths = [$databasePath.'.first-ready', $databasePath.'.second-ready'];
    $processes = [];
    $originalDatabase = config('database.connections.sqlite.database');
    $originalBusyTimeout = config('database.connections.sqlite.busy_timeout');
    $originalTransactionMode = config('database.connections.sqlite.transaction_mode');

    try {
        config()->set('database.connections.sqlite.database', $databasePath);
        config()->set('database.connections.sqlite.busy_timeout', 100);
        config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $account = Account::factory()->create(['email' => 'verified-race@example.com']);
        $sheet = Sheet::factory()->create([
            'state' => Sheet::STATE_PUBLISHED,
            'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
            'selection_maximum' => 1,
        ]);
        $option = $sheet->options()->create([
            'name' => 'One verified Account Claim',
            'capacity' => 1,
            'position' => 1,
        ]);
        DB::disconnect('sqlite');

        $results = runVerifiedParticipationRace(
            $processes,
            $databasePath,
            $sheet->public_id,
            $option->public_id,
            $gatePath,
            [
                ['account_id' => $account->id, 'name' => 'First Snapshot', 'ready_path' => $readyPaths[0]],
                ['account_id' => $account->id, 'name' => 'Second Snapshot', 'ready_path' => $readyPaths[1]],
            ],
        );

        expect(array_unique(array_column($results, 'pid')))->toHaveCount(2)
            ->and(array_column($results, 'result'))->each->toBe('success');

        DB::reconnect('sqlite');

        expect(Signup::query()->count())->toBe(1)
            ->and(Signup::query()->sole()->account_id)->toBe($account->id)
            ->and(OptionClaim::query()->count())->toBe(1)
            ->and($option->fresh()->claimed_count)->toBe(1);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }

        DB::purge('sqlite');
        config()->set('database.connections.sqlite.database', $originalDatabase);
        config()->set('database.connections.sqlite.busy_timeout', $originalBusyTimeout);
        config()->set('database.connections.sqlite.transaction_mode', $originalTransactionMode);
        DB::reconnect('sqlite');

        @unlink($gatePath);
        foreach ($readyPaths as $readyPath) {
            @unlink($readyPath);
        }
        @unlink($databasePath);
        @unlink($databasePath.'-shm');
        @unlink($databasePath.'-wal');
    }
})->repeat(5);

test('separate Verified Accounts competing for the final slot add exactly one Signup and claim', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'signup-verified-final-unit-');

    expect($databasePath)->toBeString();

    $gatePath = $databasePath.'.go';
    $readyPaths = [$databasePath.'.first-ready', $databasePath.'.second-ready'];
    $processes = [];
    $originalDatabase = config('database.connections.sqlite.database');
    $originalBusyTimeout = config('database.connections.sqlite.busy_timeout');
    $originalTransactionMode = config('database.connections.sqlite.transaction_mode');

    try {
        config()->set('database.connections.sqlite.database', $databasePath);
        config()->set('database.connections.sqlite.busy_timeout', 100);
        config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $existingAccount = Account::factory()->create();
        $firstAccount = Account::factory()->create(['email' => 'first-final-race@example.com']);
        $secondAccount = Account::factory()->create(['email' => 'second-final-race@example.com']);
        $sheet = Sheet::factory()->create([
            'state' => Sheet::STATE_PUBLISHED,
            'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
            'selection_maximum' => 1,
        ]);
        $option = $sheet->options()->create([
            'name' => 'Final verified slot',
            'capacity' => 2,
            'claimed_count' => 1,
            'position' => 1,
        ]);
        $existingSignup = $sheet->signups()->create([
            'name_snapshot' => $existingAccount->name,
            'email_snapshot' => $existingAccount->email,
        ]);
        $existingSignup->account()->associate($existingAccount);
        $existingSignup->save();
        $existingSignup->optionClaims()->create(['option_id' => $option->id]);
        DB::disconnect('sqlite');

        $results = runVerifiedParticipationRace(
            $processes,
            $databasePath,
            $sheet->public_id,
            $option->public_id,
            $gatePath,
            [
                ['account_id' => $firstAccount->id, 'name' => 'First Final Participant', 'ready_path' => $readyPaths[0]],
                ['account_id' => $secondAccount->id, 'name' => 'Second Final Participant', 'ready_path' => $readyPaths[1]],
            ],
        );
        $resultCounts = array_count_values(array_column($results, 'result'));

        expect(array_unique(array_column($results, 'pid')))->toHaveCount(2)
            ->and($resultCounts['success'] ?? 0)->toBe(1)
            ->and($resultCounts['unavailable'] ?? 0)->toBe(1);

        DB::reconnect('sqlite');

        $finalOption = $option->fresh();

        expect(Signup::query()->count())->toBe(2)
            ->and(OptionClaim::query()->count())->toBe(2)
            ->and($finalOption->claimed_count)->toBe(2)
            ->and($finalOption->claimed_count)->toBeLessThanOrEqual($finalOption->capacity);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }

        DB::purge('sqlite');
        config()->set('database.connections.sqlite.database', $originalDatabase);
        config()->set('database.connections.sqlite.busy_timeout', $originalBusyTimeout);
        config()->set('database.connections.sqlite.transaction_mode', $originalTransactionMode);
        DB::reconnect('sqlite');

        @unlink($gatePath);
        foreach ($readyPaths as $readyPath) {
            @unlink($readyPath);
        }
        @unlink($databasePath);
        @unlink($databasePath.'-shm');
        @unlink($databasePath.'-wal');
    }
})->repeat(5);
