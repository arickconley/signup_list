<?php

use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

test('Published capacity reduction serializes with a concurrent Signup without losing claims or counters', function () {
    $originalDatabase = config('database.connections.sqlite.database');
    $originalBusyTimeout = config('database.connections.sqlite.busy_timeout');
    $originalTransactionMode = config('database.connections.sqlite.transaction_mode');
    $databasePath = tempnam(sys_get_temp_dir(), 'signup-capacity-change-');

    expect($databasePath)->toBeString();

    $gatePath = $databasePath.'.go';
    $readyPath = $databasePath.'.ready';
    $process = null;

    try {
        config()->set('database.connections.sqlite.database', $databasePath);
        config()->set('database.connections.sqlite.busy_timeout', 100);
        config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $owner = Account::factory()->create();
        $sheet = Sheet::factory()->for($owner, 'owner')->create([
            'state' => Sheet::STATE_PUBLISHED,
            'participation_policy' => Sheet::PARTICIPATION_OPEN,
            'selection_maximum' => 1,
        ]);
        $option = $sheet->options()->create([
            'name' => 'Capacity changing Option',
            'capacity' => 2,
            'claimed_count' => 1,
            'position' => 1,
        ]);
        $existingSignup = $sheet->signups()->create(['name_snapshot' => 'Existing Participant']);
        $existingClaim = $existingSignup->optionClaims()->create(['option_id' => $option->id]);

        $ownerComponent = Livewire::actingAs($owner)
            ->test('pages::sheets.edit', ['sheet' => $sheet])
            ->call('startEditingOption', $option->id)
            ->set('editOptionCapacity', '1');

        $process = new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/complete-open-signup.php'),
            $databasePath,
            $sheet->public_id,
            $option->public_id,
            'Concurrent Participant',
            $readyPath,
            $gatePath,
        ], base_path());
        $process->setTimeout(10)->start();

        $readyDeadline = microtime(true) + 5;

        while (! file_exists($readyPath) && $process->isRunning() && microtime(true) < $readyDeadline) {
            usleep(5_000);
        }

        expect(file_exists($readyPath))->toBeTrue();

        touch($gatePath);

        $ownerComponent
            ->call('updateOption')
            ->assertHasNoErrors();

        $process->wait();

        $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

        expect($result['result'] ?? null)->toBeIn(['success', 'unavailable']);

        DB::reconnect('sqlite');

        $option = $option->fresh();
        $claimCount = OptionClaim::query()->where('option_id', $option->id)->count();

        expect($option->capacity)->toBe(1)
            ->and($option->claimed_count)->toBe($claimCount)
            ->and(OptionClaim::query()->find($existingClaim->id)?->signup_id)->toBe($existingSignup->id)
            ->and(Signup::query()->count())->toBe($result['result'] === 'success' ? 2 : 1)
            ->and($claimCount)->toBe($result['result'] === 'success' ? 2 : 1);

        $state = Livewire::actingAs($owner)
            ->test('pages::sheets.edit', ['sheet' => $sheet->fresh()]);

        if ($result['result'] === 'success') {
            $state->assertSee('Over-Capacity — 1 over');
        } else {
            $state->assertSee('Full — no capacity remaining');
        }
    } finally {
        if ($process?->isRunning()) {
            $process->stop();
        }

        DB::purge('sqlite');

        config()->set([
            'database.connections.sqlite.database' => $originalDatabase,
            'database.connections.sqlite.busy_timeout' => $originalBusyTimeout,
            'database.connections.sqlite.transaction_mode' => $originalTransactionMode,
        ]);

        DB::reconnect('sqlite');

        @unlink($gatePath);
        @unlink($readyPath);
        @unlink($databasePath);
        @unlink($databasePath.'-shm');
        @unlink($databasePath.'-wal');
        @unlink($databasePath.'-journal');
    }
})->repeat(5);
