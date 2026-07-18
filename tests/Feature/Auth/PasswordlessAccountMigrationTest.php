<?php

use App\Models\AccountAccessChallenge;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('the passwordless migration normalizes existing Account emails', function () {
    DB::table('users')->insert(['email' => '  Alice@Example.COM  ']);

    $migration = require database_path('migrations/2026_07_17_000000_add_passwordless_account_access.php');
    $migration->normalizeExistingAccountEmails();

    expect(DB::table('users')->value('email'))->toBe('alice@example.com');
});

test('the passwordless migration stops before changing colliding Account emails', function () {
    DB::table('users')->insert([
        ['email' => 'Alice@Example.COM'],
        ['email' => 'alice@example.com'],
    ]);

    $migration = require database_path('migrations/2026_07_17_000000_add_passwordless_account_access.php');

    expect(fn () => $migration->normalizeExistingAccountEmails())
        ->toThrow(RuntimeException::class, 'normalize to the same address');

    expect(DB::table('users')->orderBy('id')->pluck('email')->all())->toBe([
        'Alice@Example.COM',
        'alice@example.com',
    ]);
});

test('the database permits only one live access challenge per address', function () {
    $challenge = AccountAccessChallenge::query()->create([
        'public_id' => (string) Str::uuid(),
        'email' => 'alice@example.com',
        'code_hash' => 'code-hash',
        'token_hash' => 'token-hash',
        'expires_at' => now()->addMinutes(15),
    ]);

    expect(fn () => AccountAccessChallenge::query()->create([
        'public_id' => (string) Str::uuid(),
        'email' => 'alice@example.com',
        'code_hash' => 'another-code-hash',
        'token_hash' => 'another-token-hash',
        'expires_at' => now()->addMinutes(15),
    ]))->toThrow(QueryException::class);

    $challenge->update(['used_at' => now()]);

    AccountAccessChallenge::query()->create([
        'public_id' => (string) Str::uuid(),
        'email' => 'alice@example.com',
        'code_hash' => 'replacement-code-hash',
        'token_hash' => 'replacement-token-hash',
        'expires_at' => now()->addMinutes(15),
    ]);

    expect(AccountAccessChallenge::query()->count())->toBe(2);
});
