<?php

use App\Actions\CancelParticipantSignup;
use App\Exceptions\CannotCancelParticipantSignup;
use App\Models\Account;
use App\Models\Option;
use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;

/**
 * @return array{account: Account, sheet: Sheet, signup: Signup}
 */
function issue14CancellableSignup(): array
{
    $account = Account::factory()->create(['email' => 'cancel@example.com']);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 2,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Cancelling Participant',
        'email_snapshot' => $account->email,
        'phone_snapshot' => '555-0111',
        'name_consent' => true,
    ]);
    $signup->forceFill(['account_id' => $account->id])->save();

    return compact('account', 'sheet', 'signup');
}

function issue14CancellationClaim(
    Sheet $sheet,
    Signup $signup,
    string $name,
    int $position,
    int $claimedCount = 1,
): Option {
    $option = $sheet->options()->create([
        'name' => $name,
        'capacity' => 3,
        'claimed_count' => $claimedCount,
        'position' => $position,
    ]);
    $signup->optionClaims()->create(['option_id' => $option->id]);

    return $option;
}

test('associated verified Account cancels its Signup and atomically releases every capacity unit', function () {
    ['account' => $account, 'sheet' => $sheet, 'signup' => $signup] = issue14CancellableSignup();
    $sharedOption = issue14CancellationClaim($sheet, $signup, 'Shared Option', 1, claimedCount: 2);
    $releasedOption = issue14CancellationClaim($sheet, $signup, 'Released Option', 2);
    $otherSignup = $sheet->signups()->create(['name_snapshot' => 'Remaining Participant']);
    $otherClaim = $otherSignup->optionClaims()->create(['option_id' => $sharedOption->id]);

    app(CancelParticipantSignup::class)->handle($account, $signup->id);

    expect(Signup::query()->find($signup->id))->toBeNull()
        ->and(OptionClaim::query()->where('signup_id', $signup->id)->exists())->toBeFalse()
        ->and(OptionClaim::query()->find($otherClaim->id))->not->toBeNull()
        ->and($sharedOption->refresh()->claimed_count)->toBe(1)
        ->and($releasedOption->refresh()->claimed_count)->toBe(0);
});

test('unauthorized Account and non-cancellable Signup lifecycle preserve Signup claims and capacity', function (string $case) {
    ['account' => $account, 'sheet' => $sheet, 'signup' => $signup] = issue14CancellableSignup();
    $first = issue14CancellationClaim($sheet, $signup, 'First protected claim', 1);
    $second = issue14CancellationClaim($sheet, $signup, 'Second protected claim', 2);
    $actor = $account;

    match ($case) {
        'another Account' => $actor = Account::factory()->create(),
        'unverified attached Account' => $account->forceFill(['email_verified_at' => null])->save(),
        'Pending Account Association' => (function () use ($signup, $account): void {
            $signup->forceFill(['account_id' => null])->save();
            $signup->pendingAccountAssociation()->create(['account_id' => $account->id]);
        })(),
        'Unregistered Participant' => $signup->forceFill([
            'account_id' => null,
            'email_snapshot' => null,
        ])->save(),
        'Draft Sheet' => $sheet->update(['state' => Sheet::STATE_DRAFT]),
        'Archived Sheet' => $sheet->update(['state' => Sheet::STATE_ARCHIVED]),
        'deadline passed' => $sheet->update(['deadline_at' => now()->subMinute()]),
    };

    $snapshot = $signup->fresh()->only([
        'name_snapshot',
        'email_snapshot',
        'phone_snapshot',
        'name_consent',
        'email_consent',
        'phone_consent',
    ]);
    $claimIds = $signup->optionClaims()->orderBy('id')->pluck('id')->all();

    expect(fn () => app(CancelParticipantSignup::class)->handle($actor, $signup->id))
        ->toThrow(CannotCancelParticipantSignup::class);

    expect($signup->refresh()->only(array_keys($snapshot)))->toBe($snapshot)
        ->and($signup->optionClaims()->orderBy('id')->pluck('id')->all())->toBe($claimIds)
        ->and($first->refresh()->claimed_count)->toBe(1)
        ->and($second->refresh()->claimed_count)->toBe(1);
})->with([
    'another Account',
    'unverified attached Account',
    'Pending Account Association',
    'Unregistered Participant',
    'Draft Sheet',
    'Archived Sheet',
    'deadline passed',
]);

test('associated verified Account cancels its Signup on an open Verified Participation Sheet', function () {
    ['account' => $account, 'sheet' => $sheet, 'signup' => $signup] = issue14CancellableSignup();
    $sheet->update(['participation_policy' => Sheet::PARTICIPATION_VERIFIED]);
    $option = issue14CancellationClaim($sheet, $signup, 'Verified claim', 1);

    app(CancelParticipantSignup::class)->handle($account, $signup->id);

    expect(Signup::query()->find($signup->id))->toBeNull()
        ->and($option->refresh()->claimed_count)->toBe(0);
});

test('cancellation rolls back every claim counter and the Signup when a counter invariant fails', function () {
    ['account' => $account, 'sheet' => $sheet, 'signup' => $signup] = issue14CancellableSignup();
    $first = issue14CancellationClaim($sheet, $signup, 'Initially released', 1);
    $inconsistent = issue14CancellationClaim($sheet, $signup, 'Inconsistent counter', 2);
    $inconsistent->update(['claimed_count' => 0]);
    $snapshot = $signup->fresh()->only([
        'name_snapshot',
        'email_snapshot',
        'phone_snapshot',
        'name_consent',
        'email_consent',
        'phone_consent',
    ]);
    $claimIds = $signup->optionClaims()->orderBy('id')->pluck('id')->all();

    expect(fn () => app(CancelParticipantSignup::class)->handle($account, $signup->id))
        ->toThrow(LogicException::class, 'Option claimed count is inconsistent with its claims.');

    expect($signup->refresh()->only(array_keys($snapshot)))->toBe($snapshot)
        ->and($signup->optionClaims()->orderBy('id')->pluck('id')->all())->toBe($claimIds)
        ->and($first->refresh()->claimed_count)->toBe(1)
        ->and($inconsistent->refresh()->claimed_count)->toBe(0);
});
