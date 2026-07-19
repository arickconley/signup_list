<?php

use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

test('busy Owner Signup removal is bounded atomic and recoverable in Livewire', function () {
    Mail::fake();

    $originalDatabase = config('database.connections.sqlite.database');
    $originalBusyTimeout = config('database.connections.sqlite.busy_timeout');
    $originalTransactionMode = config('database.connections.sqlite.transaction_mode');
    $databasePath = tempnam(sys_get_temp_dir(), 'owner-signup-removal-busy-');

    expect($databasePath)->toBeString();

    $lockReadyPath = $databasePath.'.lock-ready';
    $lockHolder = null;

    try {
        config()->set('database.connections.sqlite.database', $databasePath);
        config()->set('database.connections.sqlite.busy_timeout', 100);
        config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $owner = Account::factory()->create();
        $sheet = Sheet::factory()->for($owner, 'owner')->create([
            'state' => Sheet::STATE_PUBLISHED,
            'selection_maximum' => 2,
        ]);
        $first = $sheet->options()->create([
            'name' => 'First claim',
            'capacity' => 2,
            'claimed_count' => 1,
            'position' => 1,
        ]);
        $second = $sheet->options()->create([
            'name' => 'Second claim',
            'capacity' => 2,
            'claimed_count' => 1,
            'position' => 2,
        ]);
        $signup = $sheet->signups()->create(['name_snapshot' => 'Busy Participant']);
        $signup->optionClaims()->createMany([
            ['option_id' => $first->id],
            ['option_id' => $second->id],
        ]);

        $component = Livewire::actingAs($owner)
            ->test('pages::sheets.signups', ['sheet' => $sheet]);

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

        expect(file_exists($lockReadyPath))->toBeTrue();

        DB::reconnect('sqlite');
        $startedAt = microtime(true);

        $component
            ->call('removeSignup', $signup->id)
            ->assertHasErrors(['removal'])
            ->assertSet('announcement', 'The Signup Sheet is busy. Please wait a moment and try again.')
            ->assertSee('The Signup Sheet is busy. Please wait a moment and try again.')
            ->assertNoRedirect();

        $elapsedMilliseconds = (microtime(true) - $startedAt) * 1000;

        expect($elapsedMilliseconds)->toBeLessThan(800)
            ->and(Signup::query()->find($signup->id))->not->toBeNull()
            ->and(OptionClaim::query()->where('signup_id', $signup->id)->count())->toBe(2)
            ->and($first->fresh()->claimed_count)->toBe(1)
            ->and($second->fresh()->claimed_count)->toBe(1);
        Mail::assertNothingQueued();

        $lockHolder->wait();

        $component
            ->call('removeSignup', $signup->id)
            ->assertHasNoErrors(['removal'])
            ->assertSet('announcement', 'The Signup for Busy Participant was removed.');

        expect(Signup::query()->find($signup->id))->toBeNull()
            ->and($first->fresh()->claimed_count)->toBe(0)
            ->and($second->fresh()->claimed_count)->toBe(0);
        Mail::assertNothingQueued();
    } finally {
        if ($lockHolder?->isRunning()) {
            $lockHolder->stop();
        }

        DB::purge('sqlite');

        config()->set([
            'database.connections.sqlite.database' => $originalDatabase,
            'database.connections.sqlite.busy_timeout' => $originalBusyTimeout,
            'database.connections.sqlite.transaction_mode' => $originalTransactionMode,
        ]);

        DB::reconnect('sqlite');

        @unlink($lockReadyPath);
        @unlink($databasePath);
        @unlink($databasePath.'-shm');
        @unlink($databasePath.'-wal');
        @unlink($databasePath.'-journal');
    }
});
