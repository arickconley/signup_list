<?php

use App\Actions\DeleteOwnerOption;
use App\Exceptions\CannotDeleteOwnerOption;
use App\Mail\OwnerChangedSignupMail;
use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Mail;

test('Owner deletes an unclaimed Published Option and preserves the remaining Option', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 2,
    ]);
    $deletedOption = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'position' => 1,
    ]);
    $remainingOption = $sheet->options()->create([
        'name' => 'Cleanup',
        'capacity' => 4,
        'claimed_count' => 1,
        'position' => 2,
    ]);
    $signup = $sheet->signups()->create(['name_snapshot' => 'Existing participant']);
    $signup->optionClaims()->create(['option_id' => $remainingOption->id]);

    app(DeleteOwnerOption::class)->handle($owner, $sheet, $deletedOption->id);

    expect($sheet->options()->whereKey($deletedOption->id)->exists())->toBeFalse()
        ->and($sheet->refresh()->selection_maximum)->toBe(1)
        ->and($remainingOption->refresh())
        ->id->toBe($remainingOption->id)
        ->capacity->toBe(4)
        ->claimed_count->toBe(1)
        ->position->toBe(1);
    Mail::assertNothingQueued();
});

test('direct deletion rejects unauthorized and cross-Sheet targets without mutation', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 2,
    ]);
    $target = $sheet->options()->create([
        'name' => 'Private target',
        'capacity' => 3,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $retained = $sheet->options()->create([
        'name' => 'Retained Option',
        'capacity' => 3,
        'claimed_count' => 1,
        'position' => 2,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Private participant',
        'email_snapshot' => 'private@example.test',
        'phone_snapshot' => '555-0101',
        'name_consent' => true,
        'email_consent' => true,
        'phone_consent' => true,
    ]);
    $signup->optionClaims()->createMany([
        ['option_id' => $target->id],
        ['option_id' => $retained->id],
    ]);
    $otherSheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $otherSheetOption = $otherSheet->options()->create([
        'name' => 'Other Sheet Option',
        'capacity' => 2,
        'position' => 1,
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
    $signupSnapshot = $signup->refresh()->only($snapshotFields);
    $optionState = collect([$target->refresh(), $retained->refresh(), $otherSheetOption->refresh()])
        ->map(fn ($option): array => $option->only([
            'id',
            'sheet_id',
            'name',
            'capacity',
            'claimed_count',
            'position',
        ]))
        ->all();
    $claimIds = OptionClaim::query()->orderBy('id')->pluck('id')->all();
    $assertUnchanged = function () use (
        $sheet,
        $otherSheet,
        $target,
        $retained,
        $otherSheetOption,
        $optionState,
        $claimIds,
        $signup,
        $snapshotFields,
        $signupSnapshot,
    ): void {
        expect($sheet->refresh()->selection_maximum)->toBe(2)
            ->and($otherSheet->refresh()->selection_maximum)->toBe(1)
            ->and(collect([$target->refresh(), $retained->refresh(), $otherSheetOption->refresh()])
                ->map(fn ($option): array => $option->only([
                    'id',
                    'sheet_id',
                    'name',
                    'capacity',
                    'claimed_count',
                    'position',
                ]))
                ->all())->toBe($optionState)
            ->and(OptionClaim::query()->orderBy('id')->pluck('id')->all())->toBe($claimIds)
            ->and($signup->refresh()->only($snapshotFields))->toBe($signupSnapshot);
        Mail::assertNothingQueued();
    };

    expect(fn () => app(DeleteOwnerOption::class)->handle($otherAccount, $sheet, $target->id))
        ->toThrow(CannotDeleteOwnerOption::class, 'This Option cannot be deleted.');
    $assertUnchanged();

    expect(fn () => app(DeleteOwnerOption::class)->handle($owner, $sheet, $otherSheetOption->id))
        ->toThrow(CannotDeleteOwnerOption::class, 'This Option cannot be deleted.');
    $assertUnchanged();
});

test('claimed Option deletion queues immutable after-commit messages to affected emailed Signups', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Neighborhood meal train',
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 2,
    ]);
    $deletedOption = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 4,
        'claimed_count' => 3,
        'position' => 1,
    ]);
    $retainedOption = $sheet->options()->create([
        'name' => 'Cleanup',
        'capacity' => 4,
        'claimed_count' => 2,
        'position' => 2,
    ]);
    $sheet->options()->create([
        'name' => 'Dessert service',
        'capacity' => 4,
        'position' => 3,
    ]);
    $firstAffected = $sheet->signups()->create([
        'name_snapshot' => 'First affected participant',
        'email_snapshot' => 'first@example.test',
    ]);
    $firstAffected->optionClaims()->createMany([
        ['option_id' => $deletedOption->id],
        ['option_id' => $retainedOption->id],
    ]);
    $secondAffected = $sheet->signups()->create([
        'name_snapshot' => 'Second affected participant',
        'email_snapshot' => 'second@example.test',
    ]);
    $secondAffected->optionClaims()->create(['option_id' => $deletedOption->id]);
    $withoutEmail = $sheet->signups()->create(['name_snapshot' => 'No-email participant']);
    $withoutEmail->optionClaims()->create(['option_id' => $deletedOption->id]);
    $unrelated = $sheet->signups()->create([
        'name_snapshot' => 'Unrelated participant',
        'email_snapshot' => 'unrelated@example.test',
    ]);
    $unrelated->optionClaims()->create(['option_id' => $retainedOption->id]);

    app(DeleteOwnerOption::class)->handle($owner, $sheet, $deletedOption->id);

    Mail::assertQueuedTimes(OwnerChangedSignupMail::class, 2);
    $mails = Mail::queued(OwnerChangedSignupMail::class);
    $firstMail = $mails->sole(
        fn (OwnerChangedSignupMail $mail): bool => $mail->hasTo('first@example.test'),
    );
    $secondMail = $mails->sole(
        fn (OwnerChangedSignupMail $mail): bool => $mail->hasTo('second@example.test'),
    );

    expect($firstMail)
        ->toBeInstanceOf(ShouldBeEncrypted::class)
        ->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($firstMail->sheetTitle)->toBe('Neighborhood meal train')
        ->and($firstMail->sheetUrl)->toBe(route('sheets.show', $sheet))
        ->and($firstMail->beforeSelectionNames)->toBe(['Welcome table', 'Cleanup'])
        ->and($firstMail->afterSelectionNames)->toBe(['Cleanup'])
        ->and($secondMail)
        ->toBeInstanceOf(ShouldBeEncrypted::class)
        ->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($secondMail->sheetTitle)->toBe('Neighborhood meal train')
        ->and($secondMail->sheetUrl)->toBe(route('sheets.show', $sheet))
        ->and($secondMail->beforeSelectionNames)->toBe(['Welcome table'])
        ->and($secondMail->afterSelectionNames)->toBe([]);
    Mail::assertNotQueued(
        OwnerChangedSignupMail::class,
        fn (OwnerChangedSignupMail $mail): bool => $mail->hasTo('unrelated@example.test'),
    );

    $sheet->update(['title' => 'Renamed Sheet']);
    $retainedOption->update(['name' => 'Renamed Option']);

    foreach ([$firstMail, $secondMail] as $mail) {
        expect(strip_tags($mail->render()))
            ->toContain('Neighborhood meal train')
            ->toContain('Welcome table')
            ->toContain('The Owner changed your Signup')
            ->not->toContain('Renamed Sheet')
            ->not->toContain('Renamed Option');
    }

    expect(strip_tags($firstMail->render()))->toContain('Cleanup')
        ->and(strip_tags($secondMail->render()))->toContain('No selections remain.');
});

test('Owner deletes a claimed Option while preserving Signups and other claims', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 2,
    ]);
    $deletedOption = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 3,
        'claimed_count' => 2,
        'position' => 1,
    ]);
    $retainedOption = $sheet->options()->create([
        'name' => 'Cleanup',
        'capacity' => 4,
        'claimed_count' => 1,
        'position' => 2,
    ]);
    $spareOption = $sheet->options()->create([
        'name' => 'Dessert service',
        'capacity' => 5,
        'position' => 3,
    ]);
    $firstSignup = $sheet->signups()->create([
        'name_snapshot' => 'First participant',
        'phone_snapshot' => '555-0101',
        'name_consent' => true,
        'email_consent' => false,
        'phone_consent' => true,
    ]);
    $secondSignup = $sheet->signups()->create([
        'name_snapshot' => 'Second participant',
        'phone_snapshot' => '555-0102',
        'name_consent' => false,
        'email_consent' => false,
        'phone_consent' => false,
    ]);
    $deletedClaims = collect([
        $firstSignup->optionClaims()->create(['option_id' => $deletedOption->id]),
        $secondSignup->optionClaims()->create(['option_id' => $deletedOption->id]),
    ]);
    $retainedClaim = $secondSignup->optionClaims()->create(['option_id' => $retainedOption->id]);
    $snapshotFields = [
        'id',
        'name_snapshot',
        'email_snapshot',
        'phone_snapshot',
        'name_consent',
        'email_consent',
        'phone_consent',
    ];
    $signupSnapshots = collect([$firstSignup, $secondSignup])
        ->map(fn ($signup): array => $signup->only($snapshotFields))
        ->all();
    $retainedOptions = collect([$retainedOption->refresh(), $spareOption->refresh()])
        ->map(fn ($option): array => $option->only(['id', 'name', 'capacity', 'claimed_count']))
        ->all();

    app(DeleteOwnerOption::class)->handle($owner, $sheet, $deletedOption->id);

    expect($sheet->options()->whereKey($deletedOption->id)->exists())->toBeFalse()
        ->and(OptionClaim::query()->whereIn('id', $deletedClaims->pluck('id'))->exists())->toBeFalse()
        ->and($sheet->signups()->orderBy('id')->get()->map(
            fn ($signup): array => $signup->only($snapshotFields),
        )->all())->toBe($signupSnapshots)
        ->and(OptionClaim::query()->find($retainedClaim->id)?->option_id)->toBe($retainedOption->id)
        ->and(collect([$retainedOption->refresh(), $spareOption->refresh()])->map(
            fn ($option): array => $option->only(['id', 'name', 'capacity', 'claimed_count']),
        )->all())->toBe($retainedOptions);
    Mail::assertNothingQueued();
});

test('Published Sheet keeps at least one Option', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Only Option',
        'capacity' => 2,
        'position' => 1,
    ]);

    expect(fn () => app(DeleteOwnerOption::class)->handle($owner, $sheet, $option->id))
        ->toThrow(CannotDeleteOwnerOption::class, 'A Published Sheet must keep at least one Option.');

    expect($sheet->options()->whereKey($option->id)->exists())->toBeTrue()
        ->and($sheet->refresh()->selection_maximum)->toBe(1);
    Mail::assertNothingQueued();
});
