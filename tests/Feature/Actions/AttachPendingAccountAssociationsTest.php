<?php

use App\Actions\AttachPendingAccountAssociations;
use App\Models\Account;
use App\Models\PendingAccountAssociation;
use App\Models\Sheet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('verified Account attaches only its normalized-email Pending Account Associations without changing Signup data', function () {
    $account = Account::factory()->create(['email' => 'participant@example.com']);
    $otherAccount = Account::factory()->create(['email' => 'other@example.com']);

    $matchingSheet = Sheet::factory()->create();
    $matchingOption = $matchingSheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 3,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $matchingSignup = $matchingSheet->signups()->create([
        'name_snapshot' => 'Submitted Name',
        'email_snapshot' => 'participant@example.com',
        'phone_snapshot' => '555-0102',
        'name_consent' => true,
        'email_consent' => false,
        'phone_consent' => true,
    ]);
    $matchingAssociation = $matchingSignup->pendingAccountAssociation()->create([
        'account_id' => $account->id,
    ]);
    $matchingClaim = $matchingSignup->optionClaims()->create([
        'option_id' => $matchingOption->id,
    ]);

    $oldEmailSheet = Sheet::factory()->create();
    $oldEmailSignup = $oldEmailSheet->signups()->create([
        'name_snapshot' => 'Old Email Snapshot',
        'email_snapshot' => 'old@example.com',
    ]);
    $oldEmailAssociation = $oldEmailSignup->pendingAccountAssociation()->create([
        'account_id' => $account->id,
    ]);

    $wrongMarkerSheet = Sheet::factory()->create();
    $wrongMarkerSignup = $wrongMarkerSheet->signups()->create([
        'name_snapshot' => 'Wrong Marker Account',
        'email_snapshot' => 'participant@example.com',
    ]);
    $wrongMarkerAssociation = $wrongMarkerSignup->pendingAccountAssociation()->create([
        'account_id' => $otherAccount->id,
    ]);

    $snapshotBefore = $matchingSignup->only([
        'name_snapshot',
        'email_snapshot',
        'phone_snapshot',
        'name_consent',
        'email_consent',
        'phone_consent',
    ]);

    app(AttachPendingAccountAssociations::class)->handle($account);
    app(AttachPendingAccountAssociations::class)->handle($account);

    expect($matchingSignup->refresh())
        ->account_id->toBe($account->id)
        ->and($matchingSignup->only(array_keys($snapshotBefore)))->toBe($snapshotBefore)
        ->and($matchingSignup->optionClaims()->pluck('id')->all())->toBe([$matchingClaim->id])
        ->and($matchingOption->refresh()->claimed_count)->toBe(1)
        ->and(PendingAccountAssociation::query()->find($matchingAssociation->id))->toBeNull()
        ->and($oldEmailSignup->refresh()->account_id)->toBeNull()
        ->and(PendingAccountAssociation::query()->find($oldEmailAssociation->id))->not->toBeNull()
        ->and($wrongMarkerSignup->refresh()->account_id)->toBeNull()
        ->and(PendingAccountAssociation::query()->find($wrongMarkerAssociation->id))->not->toBeNull();
});

test('database permits only one attached Signup per Account and Signup Sheet', function () {
    $account = Account::factory()->create();
    $sheet = Sheet::factory()->create();

    $firstSignup = $sheet->signups()->create([
        'name_snapshot' => 'First Signup',
    ]);
    $firstSignup->forceFill(['account_id' => $account->id])->save();

    expect(function () use ($sheet, $account): void {
        $secondSignup = $sheet->signups()->create([
            'name_snapshot' => 'Second Signup',
        ]);
        $secondSignup->forceFill(['account_id' => $account->id])->save();
    })->toThrow(QueryException::class);
});

test('unverified Account leaves its Pending Account Associations untouched', function () {
    $account = Account::factory()->unverified()->create([
        'email' => 'pending@example.com',
    ]);
    $signup = Sheet::factory()->create()->signups()->create([
        'name_snapshot' => 'Pending Participant',
        'email_snapshot' => 'pending@example.com',
    ]);
    $association = $signup->pendingAccountAssociation()->create([
        'account_id' => $account->id,
    ]);

    app(AttachPendingAccountAssociations::class)->handle($account);

    expect($signup->refresh()->account_id)->toBeNull()
        ->and(PendingAccountAssociation::query()->find($association->id))->not->toBeNull();
});

test('attachment uses the current Account email instead of a stale caller email', function () {
    $staleAccount = Account::factory()->create(['email' => 'old@example.com']);

    $oldEmailSignup = Sheet::factory()->create()->signups()->create([
        'name_snapshot' => 'Old Email Signup',
        'email_snapshot' => 'old@example.com',
    ]);
    $oldEmailAssociation = $oldEmailSignup->pendingAccountAssociation()->create([
        'account_id' => $staleAccount->id,
    ]);

    DB::table('users')->where('id', $staleAccount->id)->update([
        'email' => 'new@example.com',
        'email_verified_at' => now(),
    ]);

    $newEmailSignup = Sheet::factory()->create()->signups()->create([
        'name_snapshot' => 'Current Email Signup',
        'email_snapshot' => 'new@example.com',
    ]);
    $newEmailAssociation = $newEmailSignup->pendingAccountAssociation()->create([
        'account_id' => $staleAccount->id,
    ]);

    app(AttachPendingAccountAssociations::class)->handle($staleAccount);

    expect($oldEmailSignup->refresh()->account_id)->toBeNull()
        ->and(PendingAccountAssociation::query()->find($oldEmailAssociation->id))->not->toBeNull()
        ->and($newEmailSignup->refresh()->account_id)->toBe($staleAccount->id)
        ->and(PendingAccountAssociation::query()->find($newEmailAssociation->id))->toBeNull();
});

test('direct attachment rolls back every candidate when one marker cannot be deleted', function () {
    $account = Account::factory()->create(['email' => 'atomic@example.com']);
    $firstSignup = Sheet::factory()->create()->signups()->create([
        'name_snapshot' => 'First Candidate',
        'email_snapshot' => 'atomic@example.com',
    ]);
    $firstAssociation = $firstSignup->pendingAccountAssociation()->create([
        'account_id' => $account->id,
    ]);
    $secondSignup = Sheet::factory()->create()->signups()->create([
        'name_snapshot' => 'Second Candidate',
        'email_snapshot' => 'atomic@example.com',
    ]);
    $secondAssociation = $secondSignup->pendingAccountAssociation()->create([
        'account_id' => $account->id,
    ]);

    DB::unprepared(sprintf(<<<'SQL'
        CREATE TRIGGER reject_pending_marker_delete
        BEFORE DELETE ON pending_account_associations
        WHEN OLD.id = %d
        BEGIN
            SELECT RAISE(ABORT, 'reject pending marker delete');
        END
        SQL, $secondAssociation->id));

    expect(fn () => app(AttachPendingAccountAssociations::class)->handle($account))
        ->toThrow(QueryException::class);

    expect($firstSignup->refresh()->account_id)->toBeNull()
        ->and($secondSignup->refresh()->account_id)->toBeNull()
        ->and(PendingAccountAssociation::query()->find($firstAssociation->id))->not->toBeNull()
        ->and(PendingAccountAssociation::query()->find($secondAssociation->id))->not->toBeNull();
});
