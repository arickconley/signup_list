<?php

use App\Actions\DeleteOwnerOption;
use App\Exceptions\CannotDeleteOwnerOption;
use App\Mail\OwnerChangedSignupMail;
use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;

test('busy Owner Option deletion is bounded atomic and recoverable around a concurrent Signup', function () {
    Mail::fake();

    $originalDefaultConnection = config('database.default');
    $originalDatabase = config('database.connections.sqlite.database');
    $originalBusyTimeout = config('database.connections.sqlite.busy_timeout');
    $originalTransactionMode = config('database.connections.sqlite.transaction_mode');
    $databasePath = tempnam(sys_get_temp_dir(), 'owner-option-deletion-busy-');

    expect($databasePath)->toBeString();

    $readyPath = $databasePath.'.signup-ready';
    $releasePath = $databasePath.'.signup-release';
    $signupProcess = null;

    try {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', $databasePath);
        config()->set('database.connections.sqlite.busy_timeout', 100);
        config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $owner = Account::factory()->create();
        $sheet = Sheet::factory()->for($owner, 'owner')->create([
            'state' => Sheet::STATE_PUBLISHED,
            'participation_policy' => Sheet::PARTICIPATION_OPEN,
            'selection_maximum' => 2,
        ]);
        $target = $sheet->options()->create([
            'name' => 'Concurrent target',
            'capacity' => 4,
            'claimed_count' => 1,
            'position' => 1,
        ]);
        $retained = $sheet->options()->create([
            'name' => 'Retained Option',
            'capacity' => 4,
            'claimed_count' => 1,
            'position' => 2,
        ]);
        $existingSignup = $sheet->signups()->create([
            'name_snapshot' => 'Existing participant',
            'email_snapshot' => 'existing@example.test',
            'phone_snapshot' => '555-0101',
            'name_consent' => true,
            'email_consent' => true,
            'phone_consent' => true,
        ]);
        $existingSignup->optionClaims()->createMany([
            ['option_id' => $target->id],
            ['option_id' => $retained->id],
        ]);
        $snapshotFields = [
            'id',
            'name_snapshot',
            'email_snapshot',
            'phone_snapshot',
            'name_consent',
            'email_consent',
            'phone_consent',
        ];
        $existingSnapshot = $existingSignup->refresh()->only($snapshotFields);
        $optionStateBeforeFailure = collect([$target->refresh(), $retained->refresh()])
            ->map(fn ($option): array => $option->only([
                'id',
                'name',
                'capacity',
                'claimed_count',
                'position',
            ]))
            ->all();
        $claimIdsBeforeFailure = $existingSignup->optionClaims()->orderBy('id')->pluck('id')->all();

        DB::disconnect('sqlite');

        $signupProcess = new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/complete-open-signup-inside-held-transaction.php'),
            $databasePath,
            $sheet->public_id,
            $target->public_id,
            $readyPath,
            $releasePath,
        ], base_path(), [
            'APP_KEY' => 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
        ]);
        $signupProcess->setTimeout(15)->start();

        $readyDeadline = microtime(true) + 5;

        while (
            ! file_exists($readyPath)
            && $signupProcess->isRunning()
            && microtime(true) < $readyDeadline
        ) {
            usleep(5_000);
        }

        expect(file_exists($readyPath))->toBeTrue();

        DB::reconnect('sqlite');
        $startedAt = microtime(true);
        $failure = null;

        try {
            app(DeleteOwnerOption::class)->handle($owner, $sheet, $target->id);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $elapsedMilliseconds = (microtime(true) - $startedAt) * 1000;

        expect($elapsedMilliseconds)->toBeLessThan(800)
            ->and($sheet->refresh()->selection_maximum)->toBe(2)
            ->and($sheet->options()->orderBy('id')->get()->map(
                fn ($option): array => $option->only([
                    'id',
                    'name',
                    'capacity',
                    'claimed_count',
                    'position',
                ]),
            )->all())->toBe($optionStateBeforeFailure)
            ->and(OptionClaim::query()->orderBy('id')->pluck('id')->all())->toBe($claimIdsBeforeFailure)
            ->and($existingSignup->refresh()->only($snapshotFields))->toBe($existingSnapshot)
            ->and($failure)->toBeInstanceOf(CannotDeleteOwnerOption::class)
            ->getMessage()->toBe('The Signup Sheet is busy. Please wait a moment and try again.');
        Mail::assertNothingQueued();

        file_put_contents($releasePath, 'release');
        $signupProcess->wait();

        expect($signupProcess->isSuccessful())->toBeTrue()
            ->and($signupProcess->getOutput())->toContain('committed');

        DB::disconnect('sqlite');
        DB::reconnect('sqlite');

        $concurrentSignup = Signup::query()
            ->where('sheet_id', $sheet->id)
            ->where('name_snapshot', 'Concurrent participant')
            ->firstOrFail();
        $committedSnapshots = Signup::query()
            ->where('sheet_id', $sheet->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Signup $signup): array => $signup->only($snapshotFields))
            ->all();

        expect($target->refresh()->claimed_count)->toBe(2)
            ->and($retained->refresh()->claimed_count)->toBe(1)
            ->and(OptionClaim::query()->where('option_id', $target->id)->count())->toBe(2)
            ->and($concurrentSignup->optionClaims()->where('option_id', $target->id)->exists())->toBeTrue();
        Mail::assertNothingQueued();

        app(DeleteOwnerOption::class)->handle($owner, $sheet, $target->id);

        expect($sheet->options()->whereKey($target->id)->exists())->toBeFalse()
            ->and(OptionClaim::query()->where('option_id', $target->id)->exists())->toBeFalse()
            ->and(Signup::query()->where('sheet_id', $sheet->id)->orderBy('id')->get()->map(
                fn (Signup $signup): array => $signup->only($snapshotFields),
            )->all())->toBe($committedSnapshots)
            ->and($retained->refresh())
            ->id->toBe($retained->id)
            ->claimed_count->toBe(1)
            ->position->toBe(1)
            ->and($existingSignup->optionClaims()->where('option_id', $retained->id)->exists())->toBeTrue()
            ->and($concurrentSignup->optionClaims()->exists())->toBeFalse()
            ->and($sheet->refresh()->selection_maximum)->toBe(1);
        Mail::assertQueuedTimes(OwnerChangedSignupMail::class, 1);
        Mail::assertQueued(
            OwnerChangedSignupMail::class,
            fn (OwnerChangedSignupMail $mail): bool => $mail->hasTo('existing@example.test'),
        );
    } finally {
        if ($signupProcess?->isRunning()) {
            file_put_contents($releasePath, 'release');

            try {
                $signupProcess->wait();
            } catch (Throwable) {
                $signupProcess->stop();
            }
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
        @unlink($releasePath);
        @unlink($databasePath);
        @unlink($databasePath.'-shm');
        @unlink($databasePath.'-wal');
        @unlink($databasePath.'-journal');
    }
});
