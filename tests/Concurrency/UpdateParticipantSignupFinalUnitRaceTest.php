<?php

use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

test('separate participant edits competing for one remaining unit preserve the losing Signup', function () {
    $originalDatabase = config('database.connections.sqlite.database');
    $originalBusyTimeout = config('database.connections.sqlite.busy_timeout');
    $originalTransactionMode = config('database.connections.sqlite.transaction_mode');

    $databasePath = tempnam(sys_get_temp_dir(), 'signup-edit-final-unit-');

    expect($databasePath)->toBeString();

    $gatePath = $databasePath.'.go';
    $firstReadyPath = $databasePath.'.first-ready';
    $secondReadyPath = $databasePath.'.second-ready';
    $processes = [];

    try {
        config()->set('database.connections.sqlite.database', $databasePath);
        config()->set('database.connections.sqlite.busy_timeout', 100);
        config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $sheet = Sheet::factory()->create([
            'state' => Sheet::STATE_PUBLISHED,
            'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
            'selection_maximum' => 1,
        ]);
        $firstAccount = Account::factory()->create();
        $secondAccount = Account::factory()->create();
        $firstSignup = $sheet->signups()->create([
            'name_snapshot' => 'First Participant',
            'email_snapshot' => $firstAccount->email,
        ]);
        $firstSignup->forceFill(['account_id' => $firstAccount->id])->save();
        $secondSignup = $sheet->signups()->create([
            'name_snapshot' => 'Second Participant',
            'email_snapshot' => $secondAccount->email,
        ]);
        $secondSignup->forceFill(['account_id' => $secondAccount->id])->save();

        $firstOriginal = $sheet->options()->create([
            'name' => 'First original claim',
            'capacity' => 1,
            'claimed_count' => 1,
            'position' => 1,
        ]);
        $secondOriginal = $sheet->options()->create([
            'name' => 'Second original claim',
            'capacity' => 1,
            'claimed_count' => 1,
            'position' => 2,
        ]);
        $target = $sheet->options()->create([
            'name' => 'Single remaining target',
            'capacity' => 1,
            'position' => 3,
        ]);
        $firstSignup->optionClaims()->create(['option_id' => $firstOriginal->id]);
        $secondSignup->optionClaims()->create(['option_id' => $secondOriginal->id]);

        DB::disconnect('sqlite');

        $fixture = base_path('tests/Fixtures/update-participant-signup.php');
        $firstProcess = new Process([
            PHP_BINARY,
            $fixture,
            $databasePath,
            (string) $firstAccount->id,
            (string) $firstSignup->id,
            $target->public_id,
            $firstReadyPath,
            $gatePath,
        ], base_path());
        $secondProcess = new Process([
            PHP_BINARY,
            $fixture,
            $databasePath,
            (string) $secondAccount->id,
            (string) $secondSignup->id,
            $target->public_id,
            $secondReadyPath,
            $gatePath,
        ], base_path());

        $processes = [$firstProcess, $secondProcess];

        foreach ($processes as $process) {
            $process->setTimeout(10)->start();
        }

        $readyDeadline = microtime(true) + 5;

        while (
            (! file_exists($firstReadyPath) || ! file_exists($secondReadyPath))
            && ($processes[0]->isRunning() || $processes[1]->isRunning())
            && microtime(true) < $readyDeadline
        ) {
            usleep(5_000);
        }

        expect(file_exists($firstReadyPath))->toBeTrue()
            ->and(file_exists($secondReadyPath))->toBeTrue();

        touch($gatePath);

        foreach ($processes as $process) {
            $process->wait();
        }

        $results = array_map(
            fn (Process $process): array => json_decode(
                trim($process->getOutput()),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            $processes,
        );
        $readyPids = [
            (int) file_get_contents($firstReadyPath),
            (int) file_get_contents($secondReadyPath),
        ];
        $resultPids = array_map('intval', array_column($results, 'pid'));
        $resultCounts = array_count_values(array_column($results, 'result'));
        $winner = collect($results)->firstWhere('result', 'success');
        $loser = collect($results)->firstWhere('result', 'unavailable');

        expect($readyPids)->not->toContain(0)
            ->and(array_unique($readyPids))->toHaveCount(2)
            ->and(array_unique($resultPids))->toHaveCount(2)
            ->and($resultPids)->toEqualCanonicalizing($readyPids)
            ->and(array_unique(array_column($results, 'busy_timeout')))->toBe([100])
            ->and($resultCounts['success'] ?? 0)->toBe(1)
            ->and($resultCounts['unavailable'] ?? 0)->toBe(1)
            ->and($winner)->toBeArray()
            ->and($loser)->toBeArray()
            ->and(max(array_column($results, 'observed_target_claimed_count')))->toBeLessThanOrEqual(1);

        DB::reconnect('sqlite');

        $winnerSignupId = (int) $winner['signup_id'];
        $loserSignupId = (int) $loser['signup_id'];
        $loserOriginalOptionId = (int) $loser['original_option_id'];

        expect(OptionClaim::query()
            ->where('signup_id', $winnerSignupId)
            ->pluck('option_id')
            ->all())->toBe([$target->id])
            ->and(OptionClaim::query()
                ->where('signup_id', $loserSignupId)
                ->pluck('option_id')
                ->all())->toBe([$loserOriginalOptionId])
            ->and(OptionClaim::query()->count())->toBe(2)
            ->and($target->fresh()->claimed_count)->toBe(1)
            ->and($target->fresh()->claimed_count)->toBeLessThanOrEqual($target->capacity);

        foreach ([$firstOriginal, $secondOriginal, $target] as $option) {
            expect($option->fresh()->claimed_count)->toBe(
                OptionClaim::query()->where('option_id', $option->id)->count(),
            );
        }
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
        @unlink($firstReadyPath);
        @unlink($secondReadyPath);
        @unlink($databasePath);
        @unlink($databasePath.'-shm');
        @unlink($databasePath.'-wal');
    }
})->repeat(5);
