<?php

use App\Actions\DeleteAccount;
use App\Data\AccountDeletionSummary;
use App\Exceptions\CannotDeleteAccount;
use App\Models\Account;
use App\Models\AccountAccessChallenge;
use App\Models\Option;
use App\Models\OptionClaim;
use App\Models\PendingAccountAssociation;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

test('Account deletion removes the complete owned graph and security footprint without mail', function () {
    Mail::fake();

    $account = Account::factory()->withTwoFactor()->create([
        'email' => 'delete@example.test',
        'phone' => '555-0100',
    ]);
    $sheet = Sheet::factory()->for($account, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Private Option',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Private Participant',
        'email_snapshot' => 'private@example.test',
        'phone_snapshot' => '555-0101',
        'name_consent' => true,
        'email_consent' => true,
        'phone_consent' => true,
    ]);
    $signup->forceFill(['account_id' => $account->id])->save();
    $claim = $signup->optionClaims()->create(['option_id' => $option->id]);
    $association = $signup->pendingAccountAssociation()->create(['account_id' => $account->id]);
    $challenge = AccountAccessChallenge::query()->create([
        'public_id' => (string) Str::uuid(),
        'email' => $account->email,
        'code_hash' => Hash::make('123456'),
        'token_hash' => Hash::make(Str::random(64)),
        'expires_at' => now()->addMinutes(10),
    ]);
    DB::table('password_reset_tokens')->insert([
        'email' => $account->email,
        'token' => 'private-reset-token',
        'created_at' => now(),
    ]);
    DB::table('sessions')->insert([
        'id' => 'private-session',
        'user_id' => $account->id,
        'ip_address' => '192.0.2.1',
        'user_agent' => 'Private agent',
        'payload' => 'private payload',
        'last_activity' => now()->timestamp,
    ]);
    $passkeyId = DB::table('passkeys')->insertGetId([
        'user_id' => $account->id,
        'name' => 'Private passkey',
        'credential_id' => 'private-credential',
        'credential' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(DeleteAccount::class)->handle($account, AccountDeletionSummary::for($account));

    expect(Account::query()->find($account->id))->toBeNull()
        ->and(Sheet::query()->find($sheet->id))->toBeNull()
        ->and(Option::query()->find($option->id))->toBeNull()
        ->and(Signup::query()->find($signup->id))->toBeNull()
        ->and(OptionClaim::query()->find($claim->id))->toBeNull()
        ->and(PendingAccountAssociation::query()->find($association->id))->toBeNull()
        ->and(AccountAccessChallenge::query()->find($challenge->id))->toBeNull()
        ->and(DB::table('password_reset_tokens')->where('email', $account->email)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $account->id)->exists())->toBeFalse()
        ->and(DB::table('passkeys')->where('id', $passkeyId)->exists())->toBeFalse();

    Mail::assertNothingQueued();
});

test('foreign-owner Signups stay capacity-bearing while Account associations and identity are erased', function () {
    $account = Account::factory()->create(['email' => 'participant@example.test']);
    $owner = Account::factory()->create();

    $attachedSheet = Sheet::factory()->for($owner, 'owner')->create();
    $attachedOption = $attachedSheet->options()->create([
        'name' => 'Attached claim',
        'capacity' => 3,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $attachedSignup = $attachedSheet->signups()->create([
        'name_snapshot' => 'Identifying Name',
        'email_snapshot' => 'participant@example.test',
        'phone_snapshot' => '555-0102',
        'name_consent' => true,
        'email_consent' => true,
        'phone_consent' => true,
    ]);
    $attachedSignup->forceFill(['account_id' => $account->id])->save();
    $attachedClaim = $attachedSignup->optionClaims()->create(['option_id' => $attachedOption->id]);

    $pendingSheet = Sheet::factory()->for($owner, 'owner')->create();
    $pendingOption = $pendingSheet->options()->create([
        'name' => 'Pending claim',
        'capacity' => 3,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $pendingSignup = $pendingSheet->signups()->create([
        'name_snapshot' => 'Pending Identifying Name',
        'email_snapshot' => 'participant@example.test',
        'phone_snapshot' => '555-0103',
        'name_consent' => true,
        'email_consent' => true,
        'phone_consent' => true,
    ]);
    $pendingAssociation = $pendingSignup->pendingAccountAssociation()->create([
        'account_id' => $account->id,
    ]);
    $pendingClaim = $pendingSignup->optionClaims()->create(['option_id' => $pendingOption->id]);

    $unrelatedSheet = Sheet::factory()->for($owner, 'owner')->create();
    $unrelatedSignup = $unrelatedSheet->signups()->create([
        'name_snapshot' => 'Same Email, Unrelated Person',
        'email_snapshot' => 'participant@example.test',
        'phone_snapshot' => '555-0199',
        'name_consent' => true,
        'email_consent' => true,
        'phone_consent' => true,
    ]);

    app(DeleteAccount::class)->handle($account, AccountDeletionSummary::for($account));

    foreach ([$attachedSignup->refresh(), $pendingSignup->refresh()] as $retainedSignup) {
        expect($retainedSignup)
            ->account_id->toBeNull()
            ->name_snapshot->toBe('Deleted participant')
            ->email_snapshot->toBeNull()
            ->phone_snapshot->toBeNull()
            ->name_consent->toBeFalse()
            ->email_consent->toBeFalse()
            ->phone_consent->toBeFalse();
    }

    expect(OptionClaim::query()->find($attachedClaim->id))->not->toBeNull()
        ->and(OptionClaim::query()->find($pendingClaim->id))->not->toBeNull()
        ->and($attachedOption->refresh()->claimed_count)->toBe(1)
        ->and($pendingOption->refresh()->claimed_count)->toBe(1)
        ->and(PendingAccountAssociation::query()->find($pendingAssociation->id))->toBeNull()
        ->and($unrelatedSignup->refresh())
        ->name_snapshot->toBe('Same Email, Unrelated Person')
        ->email_snapshot->toBe('participant@example.test')
        ->phone_snapshot->toBe('555-0199');
});

test('stale Signup Sheet counts cancel deletion atomically', function () {
    $account = Account::factory()->create();
    $firstSheet = Sheet::factory()->for($account, 'owner')->create([
        'state' => Sheet::STATE_DRAFT,
    ]);
    $confirmedSummary = AccountDeletionSummary::for($account);
    $newSheet = Sheet::factory()->for($account, 'owner')->create([
        'state' => Sheet::STATE_ARCHIVED,
    ]);

    expect(fn () => app(DeleteAccount::class)->handle($account, $confirmedSummary))
        ->toThrow(CannotDeleteAccount::class, 'Your Signup Sheet summary changed. Review it and confirm again.');

    expect($account->fresh())->not->toBeNull()
        ->and($firstSheet->fresh())->not->toBeNull()
        ->and($newSheet->fresh())->not->toBeNull();
});

test('database failure rolls back every deletion and anonymization with a safe error', function () {
    $account = Account::factory()->create(['email' => 'rollback@example.test']);
    $owner = Account::factory()->create();
    $ownedSheet = Sheet::factory()->for($account, 'owner')->create();
    $foreignSheet = Sheet::factory()->for($owner, 'owner')->create();
    $foreignSignup = $foreignSheet->signups()->create([
        'name_snapshot' => 'Must Survive',
        'email_snapshot' => 'rollback@example.test',
        'phone_snapshot' => '555-0140',
        'name_consent' => true,
        'email_consent' => true,
        'phone_consent' => true,
    ]);
    $foreignSignup->forceFill(['account_id' => $account->id])->save();
    $challenge = AccountAccessChallenge::query()->create([
        'public_id' => (string) Str::uuid(),
        'email' => $account->email,
        'code_hash' => Hash::make('123456'),
        'token_hash' => Hash::make(Str::random(64)),
        'expires_at' => now()->addMinutes(10),
    ]);

    DB::statement(sprintf(
        "CREATE TRIGGER fail_account_deletion BEFORE DELETE ON users WHEN OLD.id = %d BEGIN SELECT RAISE(ABORT, 'forced account deletion failure'); END",
        $account->id,
    ));

    expect(fn () => app(DeleteAccount::class)->handle($account, AccountDeletionSummary::for($account)))
        ->toThrow(CannotDeleteAccount::class, 'This Account cannot be deleted. Nothing was changed.');

    expect($account->fresh())->not->toBeNull()
        ->and($ownedSheet->fresh())->not->toBeNull()
        ->and($foreignSignup->refresh())
        ->account_id->toBe($account->id)
        ->name_snapshot->toBe('Must Survive')
        ->email_snapshot->toBe('rollback@example.test')
        ->phone_snapshot->toBe('555-0140')
        ->and($challenge->fresh())->not->toBeNull();
});

test('unavailable Account fails without exposing identity or existence', function () {
    $account = Account::factory()->create(['email' => 'private-account@example.test']);
    $summary = AccountDeletionSummary::for($account);
    $account->delete();

    $failure = null;

    try {
        app(DeleteAccount::class)->handle($account, $summary);
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(CannotDeleteAccount::class)
        ->getMessage()->toBe('This Account cannot be deleted. Nothing was changed.')
        ->not->toContain((string) $account->id)
        ->not->toContain($account->email);
});
