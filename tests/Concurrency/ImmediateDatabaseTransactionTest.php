<?php

use App\Actions\AttachPendingAccountAssociations;
use App\Actions\ConsumeAccountAccessChallenge;
use App\Models\Account;
use App\Models\AccountAccessChallenge;
use App\Models\PendingAccountAssociation;
use App\Models\Sheet;
use App\Support\ImmediateDatabaseTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

test('account access consumption reuses a shared active PDO transaction', function () {
    $originalDatabase = config()->get('database.connections.sqlite.database');
    $originalTransactionMode = config()->get('database.connections.sqlite.transaction_mode');
    $databasePath = tempnam(sys_get_temp_dir(), 'signup-nested-transaction-');

    expect($databasePath)->toBeString();

    try {
        config()->set('database.connections.sqlite.database', $databasePath);
        config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $account = Account::factory()->unverified()->create([
            'email' => 'participant@example.com',
        ]);
        $signup = Sheet::factory()->create()->signups()->create([
            'name_snapshot' => 'Participant Snapshot',
            'email_snapshot' => 'participant@example.com',
        ]);
        $association = $signup->pendingAccountAssociation()->create([
            'account_id' => $account->id,
        ]);
        $challenge = AccountAccessChallenge::query()->create([
            'public_id' => (string) Str::uuid(),
            'email' => 'participant@example.com',
            'code_hash' => Hash::make('123456'),
            'token_hash' => Hash::make(Str::random(64)),
            'expires_at' => now()->addMinutes(10),
        ]);

        $consume = new ConsumeAccountAccessChallenge(
            new ImmediateDatabaseTransaction,
            new AttachPendingAccountAssociations(new ImmediateDatabaseTransaction),
        );

        $result = $consume->usingCode($challenge->public_id, '123456');

        expect($result?->is($account))->toBeTrue()
            ->and($account->refresh()->hasVerifiedEmail())->toBeTrue()
            ->and($signup->refresh()->account_id)->toBe($account->id)
            ->and(PendingAccountAssociation::query()->find($association->id))->toBeNull()
            ->and($challenge->refresh()->used_at)->not->toBeNull()
            ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
    } finally {
        DB::purge('sqlite');
        @unlink($databasePath);
        @unlink($databasePath.'-shm');
        @unlink($databasePath.'-wal');
        config()->set('database.connections.sqlite.database', $originalDatabase);
        config()->set('database.connections.sqlite.transaction_mode', $originalTransactionMode);
        DB::purge('sqlite');
    }
});

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
