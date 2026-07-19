<?php

use App\Actions\UpdateParticipantSignup;
use App\Data\UpdateParticipantSignupInput;
use App\Exceptions\CannotUpdateParticipantSignup;
use App\Models\Account;
use App\Models\Option;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Support\Str;

/**
 * @return array{account: Account, sheet: Sheet, signup: Signup}
 */
function issue14EditableSignup(int $selectionMaximum = 2): array
{
    $account = Account::factory()->create(['email' => 'participant@example.com']);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => $selectionMaximum,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Original Name',
        'email_snapshot' => $account->email,
        'phone_snapshot' => '555-0101',
    ]);
    $signup->forceFill(['account_id' => $account->id])->save();

    return compact('account', 'sheet', 'signup');
}

function issue14ClaimedOption(
    Sheet $sheet,
    Signup $signup,
    string $name,
    int $position,
    int $capacity = 3,
): Option {
    $option = $sheet->options()->create([
        'name' => $name,
        'capacity' => $capacity,
        'claimed_count' => 1,
        'position' => $position,
    ]);
    $signup->optionClaims()->create(['option_id' => $option->id]);

    return $option;
}

function issue14UpdateInput(Signup $signup, array $optionPublicIds): UpdateParticipantSignupInput
{
    return new UpdateParticipantSignupInput(
        signupId: $signup->id,
        name: 'Updated Name',
        phone: '555-0199',
        optionPublicIds: $optionPublicIds,
        nameConsent: true,
        emailConsent: true,
        phoneConsent: true,
    );
}

test('associated verified Account updates its Signup snapshots consent and Option Claims without changing email identity', function () {
    $account = Account::factory()->create(['email' => 'participant@example.com']);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
        'name_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'email_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'phone_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
    ]);
    $originalOption = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $replacementOption = $sheet->options()->create([
        'name' => 'Cleanup',
        'capacity' => 2,
        'position' => 2,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Original Name',
        'email_snapshot' => $account->email,
        'phone_snapshot' => '555-0101',
    ]);
    $signup->forceFill(['account_id' => $account->id])->save();
    $signup->optionClaims()->create(['option_id' => $originalOption->id]);

    app(UpdateParticipantSignup::class)->handle($account, new UpdateParticipantSignupInput(
        signupId: $signup->id,
        name: 'Updated Name',
        phone: '555-0199',
        optionPublicIds: [$replacementOption->public_id],
        nameConsent: true,
        emailConsent: true,
        phoneConsent: true,
    ));

    expect($signup->refresh())
        ->name_snapshot->toBe('Updated Name')
        ->email_snapshot->toBe('participant@example.com')
        ->phone_snapshot->toBe('555-0199')
        ->name_consent->toBeTrue()
        ->email_consent->toBeTrue()
        ->phone_consent->toBeTrue()
        ->and($signup->optionClaims()->pluck('option_id')->all())->toBe([$replacementOption->id])
        ->and($originalOption->refresh()->claimed_count)->toBe(0)
        ->and($replacementOption->refresh()->claimed_count)->toBe(1);
});

test('participant update persists consent only for participant-visible fields', function () {
    ['account' => $account, 'sheet' => $sheet, 'signup' => $signup] = issue14EditableSignup(selectionMaximum: 1);
    $sheet->update([
        'name_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'email_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'phone_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
    ]);
    $signup->update([
        'name_consent' => false,
        'email_consent' => true,
        'phone_consent' => false,
    ]);
    $option = issue14ClaimedOption($sheet, $signup, 'Consent choice', 1);

    app(UpdateParticipantSignup::class)->handle(
        $account,
        issue14UpdateInput($signup, [$option->public_id]),
    );

    expect($signup->refresh())
        ->name_consent->toBeTrue()
        ->email_consent->toBeFalse()
        ->phone_consent->toBeTrue();
});

test('unchanged Option Claims perform no counter changes', function () {
    ['account' => $account, 'sheet' => $sheet, 'signup' => $signup] = issue14EditableSignup();
    $first = issue14ClaimedOption($sheet, $signup, 'Welcome table', 1);
    $second = issue14ClaimedOption($sheet, $signup, 'Cleanup', 2);
    $claimIds = $signup->optionClaims()->orderBy('id')->pluck('id')->all();

    app(UpdateParticipantSignup::class)->handle(
        $account,
        issue14UpdateInput($signup, [$first->public_id, $second->public_id]),
    );

    expect($signup->optionClaims()->orderBy('id')->pluck('id')->all())->toBe($claimIds)
        ->and($first->refresh()->claimed_count)->toBe(1)
        ->and($second->refresh()->claimed_count)->toBe(1);
});

test('a retained full Option Claim remains valid during removal', function () {
    ['account' => $account, 'sheet' => $sheet, 'signup' => $signup] = issue14EditableSignup();
    $retainedFull = issue14ClaimedOption($sheet, $signup, 'Full but retained', 1, capacity: 1);
    $removed = issue14ClaimedOption($sheet, $signup, 'Remove me', 2);

    app(UpdateParticipantSignup::class)->handle(
        $account,
        issue14UpdateInput($signup, [$retainedFull->public_id]),
    );

    expect($signup->optionClaims()->pluck('option_id')->all())->toBe([$retainedFull->id])
        ->and($retainedFull->refresh()->claimed_count)->toBe(1)
        ->and($removed->refresh()->claimed_count)->toBe(0);
});

test('a newly selected full Option rejects the entire change', function () {
    ['account' => $account, 'sheet' => $sheet, 'signup' => $signup] = issue14EditableSignup(selectionMaximum: 1);
    $current = issue14ClaimedOption($sheet, $signup, 'Current', 1);
    $full = $sheet->options()->create([
        'name' => 'Already full',
        'capacity' => 1,
        'claimed_count' => 1,
        'position' => 2,
    ]);
    $otherSignup = $sheet->signups()->create(['name_snapshot' => 'Other Participant']);
    $otherSignup->optionClaims()->create(['option_id' => $full->id]);

    expect(fn () => app(UpdateParticipantSignup::class)->handle(
        $account,
        issue14UpdateInput($signup, [$full->public_id]),
    ))->toThrow(CannotUpdateParticipantSignup::class, 'Some selected Options just became unavailable.');

    expect($signup->refresh())
        ->name_snapshot->toBe('Original Name')
        ->phone_snapshot->toBe('555-0101')
        ->name_consent->toBeFalse()
        ->and($signup->optionClaims()->pluck('option_id')->all())->toBe([$current->id])
        ->and($current->refresh()->claimed_count)->toBe(1)
        ->and($full->refresh()->claimed_count)->toBe(1);
});

test('duplicate foreign and missing Option IDs reject without mutation', function (string $case) {
    ['account' => $account, 'sheet' => $sheet, 'signup' => $signup] = issue14EditableSignup();
    $current = issue14ClaimedOption($sheet, $signup, 'Current', 1);
    $foreign = Sheet::factory()->create()->options()->create([
        'name' => 'Foreign',
        'capacity' => 2,
        'position' => 1,
    ]);
    $desired = match ($case) {
        'duplicate' => [$current->public_id, $current->public_id],
        'foreign' => [$current->public_id, $foreign->public_id],
        'missing' => [$current->public_id, (string) Str::uuid()],
    };

    expect(fn () => app(UpdateParticipantSignup::class)->handle(
        $account,
        issue14UpdateInput($signup, $desired),
    ))->toThrow(CannotUpdateParticipantSignup::class);

    expect($signup->refresh()->name_snapshot)->toBe('Original Name')
        ->and($signup->optionClaims()->pluck('option_id')->all())->toBe([$current->id])
        ->and($current->refresh()->claimed_count)->toBe(1);
})->with(['duplicate', 'foreign', 'missing']);

test('configured selection maximum rejects additions without mutation', function () {
    ['account' => $account, 'sheet' => $sheet, 'signup' => $signup] = issue14EditableSignup(selectionMaximum: 1);
    $current = issue14ClaimedOption($sheet, $signup, 'Current', 1);
    $available = $sheet->options()->create([
        'name' => 'Available',
        'capacity' => 2,
        'position' => 2,
    ]);

    expect(fn () => app(UpdateParticipantSignup::class)->handle(
        $account,
        issue14UpdateInput($signup, [$current->public_id, $available->public_id]),
    ))->toThrow(CannotUpdateParticipantSignup::class, 'Choose between 1 and 1 available Options.');

    expect($signup->refresh()->name_snapshot)->toBe('Original Name')
        ->and($signup->optionClaims()->pluck('option_id')->all())->toBe([$current->id])
        ->and($current->refresh()->claimed_count)->toBe(1)
        ->and($available->refresh()->claimed_count)->toBe(0);
});

test('lowered maximum permits removal-only progress while Signup remains over limit', function () {
    ['account' => $account, 'sheet' => $sheet, 'signup' => $signup] = issue14EditableSignup(selectionMaximum: 2);
    $first = issue14ClaimedOption($sheet, $signup, 'First', 1);
    $second = issue14ClaimedOption($sheet, $signup, 'Second', 2);
    $third = issue14ClaimedOption($sheet, $signup, 'Third', 3);
    $removed = issue14ClaimedOption($sheet, $signup, 'Fourth', 4);

    app(UpdateParticipantSignup::class)->handle(
        $account,
        issue14UpdateInput($signup, [$first->public_id, $second->public_id, $third->public_id]),
    );

    expect($signup->optionClaims()->orderBy('option_id')->pluck('option_id')->all())
        ->toBe([$first->id, $second->id, $third->id])
        ->and($removed->refresh()->claimed_count)->toBe(0);
});

test('lowered maximum rejects every addition or swap until existing claims are compliant', function (string $case) {
    ['account' => $account, 'sheet' => $sheet, 'signup' => $signup] = issue14EditableSignup(selectionMaximum: 2);
    $first = issue14ClaimedOption($sheet, $signup, 'First', 1);
    $second = issue14ClaimedOption($sheet, $signup, 'Second', 2);
    $third = issue14ClaimedOption($sheet, $signup, 'Third', 3);
    $new = $sheet->options()->create([
        'name' => 'New Option',
        'capacity' => 2,
        'position' => 4,
    ]);
    $desired = match ($case) {
        'addition' => [$first->public_id, $second->public_id, $third->public_id, $new->public_id],
        'over-limit swap' => [$first->public_id, $second->public_id, $new->public_id],
        'same-request compliant swap' => [$first->public_id, $new->public_id],
    };

    expect(fn () => app(UpdateParticipantSignup::class)->handle(
        $account,
        issue14UpdateInput($signup, $desired),
    ))->toThrow(CannotUpdateParticipantSignup::class, 'Remove existing Option Claims before adding another Option.');

    expect($signup->refresh()->name_snapshot)->toBe('Original Name')
        ->and($signup->optionClaims()->orderBy('option_id')->pluck('option_id')->all())
        ->toBe([$first->id, $second->id, $third->id])
        ->and($new->refresh()->claimed_count)->toBe(0);
})->with(['addition', 'over-limit swap', 'same-request compliant swap']);

test('unauthorized Account and non-editable Signup lifecycle reject without changing Signup or capacity', function (string $case) {
    ['account' => $account, 'sheet' => $sheet, 'signup' => $signup] = issue14EditableSignup(selectionMaximum: 1);
    $option = issue14ClaimedOption($sheet, $signup, 'Protected claim', 1);
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
    $claimIds = $signup->optionClaims()->pluck('id')->all();

    expect(fn () => app(UpdateParticipantSignup::class)->handle(
        $actor,
        issue14UpdateInput($signup, [$option->public_id]),
    ))->toThrow(CannotUpdateParticipantSignup::class);

    expect($signup->refresh()->only(array_keys($snapshot)))->toBe($snapshot)
        ->and($signup->optionClaims()->pluck('id')->all())->toBe($claimIds)
        ->and($option->refresh()->claimed_count)->toBe(1);
})->with([
    'another Account',
    'unverified attached Account',
    'Pending Account Association',
    'Unregistered Participant',
    'Draft Sheet',
    'Archived Sheet',
    'deadline passed',
]);

test('associated verified Account updates its Signup on an open Verified Participation Sheet', function () {
    ['account' => $account, 'sheet' => $sheet, 'signup' => $signup] = issue14EditableSignup(selectionMaximum: 1);
    $sheet->update(['participation_policy' => Sheet::PARTICIPATION_VERIFIED]);
    $option = issue14ClaimedOption($sheet, $signup, 'Verified claim', 1);

    app(UpdateParticipantSignup::class)->handle(
        $account,
        issue14UpdateInput($signup, [$option->public_id]),
    );

    expect($signup->refresh())
        ->name_snapshot->toBe('Updated Name')
        ->phone_snapshot->toBe('555-0199')
        ->and($signup->optionClaims()->pluck('option_id')->all())->toBe([$option->id])
        ->and($option->refresh()->claimed_count)->toBe(1);
});
