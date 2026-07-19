<?php

use App\Models\Account;
use App\Models\AccountAccessChallenge;
use App\Models\PendingAccountAssociation;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

test('cleanup removes expired Account access challenges and keeps unexpired challenges', function () {
    $expired = AccountAccessChallenge::query()->create([
        'public_id' => (string) Str::uuid(),
        'email' => 'expired@example.test',
        'code_hash' => 'expired-code',
        'token_hash' => 'expired-token',
        'expires_at' => now()->subSecond(),
    ]);
    $unexpired = AccountAccessChallenge::query()->create([
        'public_id' => (string) Str::uuid(),
        'email' => 'unexpired@example.test',
        'code_hash' => 'unexpired-code',
        'token_hash' => 'unexpired-token',
        'expires_at' => now()->addSecond(),
    ]);

    $this->artisan('app:cleanup-expired')->assertSuccessful();

    expect($expired->fresh())->toBeNull()
        ->and($unexpired->fresh())->not->toBeNull();
});

test('cleanup removes framework sessions at the inactivity boundary', function () {
    config()->set('session.lifetime', 120);
    $expiration = now()->subMinutes(120)->timestamp;

    DB::table('sessions')->insert([
        [
            'id' => 'expired-session',
            'payload' => 'expired',
            'last_activity' => $expiration,
        ],
        [
            'id' => 'active-session',
            'payload' => 'active',
            'last_activity' => $expiration + 1,
        ],
    ]);

    $this->artisan('app:cleanup-expired')->assertSuccessful();

    expect(DB::table('sessions')->where('id', 'expired-session')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'active-session')->exists())->toBeTrue();
});

test('cleanup applies the pending Account Association retention boundary without deleting completed Signups', function () {
    config()->set('account-access.pending_association_retention_days', 30);
    $cutoff = now()->subDays(30);
    $sheet = Sheet::factory()->create();
    $option = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'position' => 1,
    ]);
    $account = Account::factory()->unverified()->create();
    $expiredSignup = $sheet->signups()->create([
        'name_snapshot' => 'Expired Association',
        'email_snapshot' => 'expired-association@example.test',
    ]);
    $expiredClaim = $expiredSignup->optionClaims()->create(['option_id' => $option->id]);
    $expiredAssociation = $expiredSignup->pendingAccountAssociation()->create(['account_id' => $account->id]);
    DB::table('pending_account_associations')
        ->where('id', $expiredAssociation->id)
        ->update(['created_at' => $cutoff]);
    $retainedSignup = $sheet->signups()->create([
        'name_snapshot' => 'Retained Association',
        'email_snapshot' => 'retained-association@example.test',
    ]);
    $retainedAssociation = $retainedSignup->pendingAccountAssociation()->create(['account_id' => $account->id]);
    DB::table('pending_account_associations')
        ->where('id', $retainedAssociation->id)
        ->update(['created_at' => $cutoff->addSecond()]);

    $this->artisan('app:cleanup-expired')->assertSuccessful();

    expect($expiredAssociation->fresh())->toBeNull()
        ->and($retainedAssociation->fresh())->not->toBeNull()
        ->and(Signup::query()->find($expiredSignup->id))->not->toBeNull()
        ->and($expiredClaim->fresh())->not->toBeNull();
});

test('cleanup is bounded and safely rerunnable', function () {
    foreach (['first', 'second'] as $label) {
        AccountAccessChallenge::query()->create([
            'public_id' => (string) Str::uuid(),
            'email' => "{$label}@example.test",
            'code_hash' => "{$label}-code",
            'token_hash' => "{$label}-token",
            'expires_at' => now()->subSecond(),
        ]);
    }

    $this->artisan('app:cleanup-expired', ['--limit' => 1])->assertSuccessful();

    expect(AccountAccessChallenge::query()->count())->toBe(1);

    $this->artisan('app:cleanup-expired', ['--limit' => 1])->assertSuccessful();
    $this->artisan('app:cleanup-expired', ['--limit' => 1])->assertSuccessful();

    expect(AccountAccessChallenge::query()->count())->toBe(0);
});

test('cleanup limit applies independently to sessions and pending Account Associations', function () {
    config()->set('account-access.pending_association_retention_days', 30);
    config()->set('session.lifetime', 120);
    $sheet = Sheet::factory()->create();
    $account = Account::factory()->unverified()->create();

    foreach (['first', 'second'] as $label) {
        DB::table('sessions')->insert([
            'id' => "{$label}-expired-session",
            'payload' => $label,
            'last_activity' => now()->subMinutes(120)->timestamp,
        ]);
        $signup = $sheet->signups()->create([
            'name_snapshot' => ucfirst($label),
            'email_snapshot' => "{$label}-association@example.test",
        ]);
        $association = $signup->pendingAccountAssociation()->create(['account_id' => $account->id]);
        DB::table('pending_account_associations')
            ->where('id', $association->id)
            ->update(['created_at' => now()->subDays(30)]);
    }

    $this->artisan('app:cleanup-expired', ['--limit' => 1])->assertSuccessful();

    expect(DB::table('sessions')->count())->toBe(1)
        ->and(PendingAccountAssociation::query()->count())->toBe(1)
        ->and(Signup::query()->count())->toBe(2);
});

test('cleanup is registered with the scheduler', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('app:cleanup-expired')
        ->assertSuccessful();
});

test('cleanup reports structured removal counts', function () {
    Log::spy();
    AccountAccessChallenge::query()->create([
        'public_id' => (string) Str::uuid(),
        'email' => 'expired@example.test',
        'code_hash' => 'expired-code',
        'token_hash' => 'expired-token',
        'expires_at' => now()->subSecond(),
    ]);

    $this->artisan('app:cleanup-expired')
        ->expectsOutput('Expired cleanup complete: challenges=1, pending_associations=0, sessions=0.')
        ->assertSuccessful();

    Log::shouldHaveReceived('info')
        ->with('maintenance.cleanup_completed', [
            'account_access_challenges' => 1,
            'pending_account_associations' => 0,
            'sessions' => 0,
            'limit_per_data_set' => 500,
        ])
        ->once();
});
