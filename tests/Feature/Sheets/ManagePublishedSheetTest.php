<?php

use App\Actions\CompleteOpenSignup;
use App\Actions\CompleteVerifiedSignup;
use App\Actions\DeleteOwnerOption;
use App\Data\CompleteSignupInput;
use App\Exceptions\CannotCompleteSignup;
use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

test('Owner edits Published Sheet content settings and selection maximum', function () {
    $this->travelTo(Carbon::parse('2026-08-01 12:00:00 UTC'));

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $sheet->options()->create(['name' => 'Setup', 'capacity' => 2, 'position' => 1]);
    $sheet->options()->create(['name' => 'Cleanup', 'capacity' => 2, 'position' => 2]);
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->assertSee('Published Sheet')
        ->set('title', 'Updated harvest supper')
        ->set('description', 'Choose how you can help after dinner.')
        ->set('eventAt', '2026-09-05T17:30')
        ->set('location', 'North field barn')
        ->set('deadlineAt', '2026-09-04T23:59')
        ->set('participationPolicy', Sheet::PARTICIPATION_VERIFIED)
        ->set('nameVisibility', Sheet::VISIBILITY_PARTICIPANTS)
        ->set('emailVisibility', Sheet::VISIBILITY_PARTICIPANTS)
        ->set('phoneVisibility', Sheet::VISIBILITY_PARTICIPANTS)
        ->set('selectionMaximum', '2')
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->assertSee('Published Sheet changes saved.');

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet->refresh()])
        ->assertSet('title', 'Updated harvest supper')
        ->assertSet('description', 'Choose how you can help after dinner.')
        ->assertSet('eventAt', '2026-09-05T17:30')
        ->assertSet('location', 'North field barn')
        ->assertSet('deadlineAt', '2026-09-04T23:59')
        ->assertSet('participationPolicy', Sheet::PARTICIPATION_VERIFIED)
        ->assertSet('nameVisibility', Sheet::VISIBILITY_PARTICIPANTS)
        ->assertSet('emailVisibility', Sheet::VISIBILITY_PARTICIPANTS)
        ->assertSet('phoneVisibility', Sheet::VISIBILITY_PARTICIPANTS)
        ->assertSet('selectionMaximum', '2')
        ->assertSee('Participants with consent');

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('Updated harvest supper')
        ->assertSee('Choose how you can help after dinner.')
        ->assertSee('North field barn')
        ->assertSee('Sep 5, 2026 at 5:30 PM PDT')
        ->assertSee('Sep 4, 2026 at 11:59 PM PDT')
        ->assertSee('Verified Participation');
});

test('Owner adds edits and reorders Published Options without replacing claims or deleting Options', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 2,
    ]);
    $first = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $second = $sheet->options()->create([
        'name' => 'Cleanup',
        'capacity' => 2,
        'position' => 2,
    ]);
    $signup = $sheet->signups()->create(['name_snapshot' => 'Existing participant']);
    $claim = $signup->optionClaims()->create(['option_id' => $first->id]);
    $this->actingAs($owner);

    $component = Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->assertDontSeeHtml('wire:click="removeOption')
        ->set('optionName', 'Dessert service')
        ->set('optionDescription', 'Plate the pies.')
        ->set('optionCapacity', '4')
        ->call('addOption')
        ->assertHasNoErrors()
        ->assertSee('Option added.');

    $added = $sheet->options()->where('name', 'Dessert service')->sole();

    $component
        ->call('startEditingOption', $first->id)
        ->set('editOptionName', 'Guest welcome')
        ->set('editOptionDescription', 'Greet arriving participants.')
        ->set('editOptionCapacity', '3')
        ->call('updateOption')
        ->assertHasNoErrors()
        ->assertSee('Option updated.')
        ->call('moveOptionUp', $added->id)
        ->assertSeeInOrder(['Guest welcome', 'Dessert service', 'Cleanup'])
        ->call('removeOption', $second->id)
        ->assertStatus(404);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet->refresh()])
        ->assertSeeInOrder(['Guest welcome', 'Dessert service', 'Cleanup'])
        ->assertDontSeeHtml('wire:click="removeOption');

    expect(Signup::query()->sole()->id)->toBe($signup->id)
        ->and($signup->optionClaims()->sole()->id)->toBe($claim->id)
        ->and($first->refresh())
        ->id->toBe($first->id)
        ->claimed_count->toBe(1)
        ->and($second->refresh()->id)->toBe($second->id);
});

test('Owner sees affected claims and cancels claimed Option deletion', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $target = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 3,
        'claimed_count' => 2,
        'position' => 1,
    ]);
    $retained = $sheet->options()->create([
        'name' => 'Cleanup',
        'capacity' => 3,
        'position' => 2,
    ]);
    $claims = collect(['First participant', 'Second participant'])->map(
        function (string $name) use ($sheet, $target) {
            $signup = $sheet->signups()->create(['name_snapshot' => $name]);

            return $signup->optionClaims()->create(['option_id' => $target->id]);
        },
    );

    Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->assertSeeHtml('wire:click="requestOptionDeletion('.$target->id.')"')
        ->call('requestOptionDeletion', $target->id)
        ->assertSet('deletingOptionId', $target->id)
        ->assertSet('deletingOptionClaimCount', 2)
        ->assertSee('Delete Welcome table?')
        ->assertSee('This will remove 2 Option Claims.')
        ->assertSee('This cannot be undone.')
        ->assertSeeHtml('wire:click="confirmOptionDeletion"')
        ->assertSeeHtml('wire:click="cancelOptionDeletion"')
        ->call('cancelOptionDeletion')
        ->assertSet('deletingOptionId', null)
        ->assertSet('deletingOptionClaimCount', 0)
        ->assertDontSee('This will remove 2 Option Claims.');

    expect($sheet->options()->whereKey($target->id)->exists())->toBeTrue()
        ->and($sheet->options()->whereKey($retained->id)->exists())->toBeTrue()
        ->and(OptionClaim::query()->whereIn('id', $claims->pluck('id'))->count())->toBe(2);
});

test('Owner confirms claimed Option deletion from the Published Sheet editor', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 2,
    ]);
    $target = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $retained = $sheet->options()->create([
        'name' => 'Cleanup',
        'capacity' => 2,
        'position' => 2,
    ]);
    $signup = $sheet->signups()->create(['name_snapshot' => 'Participant']);
    $claim = $signup->optionClaims()->create(['option_id' => $target->id]);

    Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->call('requestOptionDeletion', $target->id)
        ->call('confirmOptionDeletion')
        ->assertSet('deletingOptionId', null)
        ->assertSet('deletingOptionClaimCount', 0)
        ->assertSet('announcement', 'Option deleted.')
        ->assertSet('selectionMaximum', '1')
        ->assertDontSee('Welcome table');

    expect($sheet->options()->whereKey($target->id)->exists())->toBeFalse()
        ->and(OptionClaim::query()->find($claim->id))->toBeNull()
        ->and($sheet->options()->whereKey($retained->id)->exists())->toBeTrue()
        ->and($sheet->refresh()->selection_maximum)->toBe(1);
});

test('Owner confirms unclaimed Option deletion from the Published Sheet editor', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 2,
    ]);
    $target = $sheet->options()->create([
        'name' => 'Unclaimed table',
        'capacity' => 2,
        'position' => 1,
    ]);
    $retained = $sheet->options()->create([
        'name' => 'Cleanup',
        'capacity' => 2,
        'position' => 2,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->call('requestOptionDeletion', $target->id)
        ->assertSet('deletingOptionId', $target->id)
        ->assertSet('deletingOptionClaimCount', 0)
        ->assertSee('This will remove 0 Option Claims.')
        ->call('confirmOptionDeletion')
        ->assertSet('deletingOptionId', null)
        ->assertSet('deletingOptionClaimCount', 0)
        ->assertSet('announcement', 'Option deleted.')
        ->assertSet('selectionMaximum', '1')
        ->assertDontSee('Unclaimed table');

    expect($sheet->options()->whereKey($target->id)->exists())->toBeFalse()
        ->and($sheet->options()->whereKey($retained->id)->exists())->toBeTrue()
        ->and($retained->refresh()->position)->toBe(1)
        ->and($sheet->refresh()->selection_maximum)->toBe(1);
    Mail::assertNothingQueued();
});

test('Owner stale claimed-count confirmation preserves newly claimed Option until a fresh request', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 2,
    ]);
    $target = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 3,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $retained = $sheet->options()->create([
        'name' => 'Cleanup',
        'capacity' => 3,
        'position' => 2,
    ]);
    $existingSignup = $sheet->signups()->create([
        'name_snapshot' => 'Existing participant',
        'email_snapshot' => 'existing@example.test',
    ]);
    $existingClaim = $existingSignup->optionClaims()->create(['option_id' => $target->id]);

    $component = Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->call('requestOptionDeletion', $target->id)
        ->assertSet('deletingOptionId', $target->id)
        ->assertSet('deletingOptionClaimCount', 1)
        ->assertSee('This will remove 1 Option Claim.');

    app(CompleteOpenSignup::class)->handle(new CompleteSignupInput(
        sheetPublicId: $sheet->public_id,
        name: 'New participant',
        phone: null,
        optionPublicIds: [$target->public_id],
    ));

    $optionFields = ['id', 'sheet_id', 'name', 'capacity', 'claimed_count', 'position'];
    $optionState = $sheet->options()
        ->orderBy('position')
        ->orderBy('id')
        ->get()
        ->map(fn ($option): array => $option->only($optionFields))
        ->all();
    $signupSnapshots = $sheet->signups()->orderBy('id')->get()->map(
        fn (Signup $signup): array => $signup->only([
            'id',
            'name_snapshot',
            'email_snapshot',
            'phone_snapshot',
            'name_consent',
            'email_consent',
            'phone_consent',
        ]),
    )->all();
    $claimIds = OptionClaim::query()->orderBy('id')->pluck('id')->all();

    $component
        ->call('confirmOptionDeletion')
        ->assertHasErrors(['optionDeletion'])
        ->assertSee('This Option cannot be deleted.')
        ->assertSet('deletingOptionId', null)
        ->assertSet('deletingOptionClaimCount', 0)
        ->assertSet('announcement', 'This Option cannot be deleted.')
        ->assertSet('selectionMaximum', '2')
        ->assertDontSee('Option deleted.');

    expect($sheet->options()->whereKey($target->id)->exists())->toBeTrue()
        ->and($sheet->options()->whereKey($retained->id)->exists())->toBeTrue()
        ->and($sheet->options()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn ($option): array => $option->only($optionFields))
            ->all())->toBe($optionState)
        ->and(OptionClaim::query()->orderBy('id')->pluck('id')->all())->toBe($claimIds)
        ->and(OptionClaim::query()->find($existingClaim->id)?->option_id)->toBe($target->id)
        ->and($sheet->signups()->orderBy('id')->get()->map(
            fn (Signup $signup): array => $signup->only([
                'id',
                'name_snapshot',
                'email_snapshot',
                'phone_snapshot',
                'name_consent',
                'email_consent',
                'phone_consent',
            ]),
        )->all())->toBe($signupSnapshots)
        ->and($sheet->refresh()->selection_maximum)->toBe(2);
    Mail::assertNothingQueued();

    $component
        ->call('requestOptionDeletion', $target->id)
        ->assertSet('deletingOptionClaimCount', 2)
        ->call('confirmOptionDeletion')
        ->assertHasNoErrors()
        ->assertSet('announcement', 'Option deleted.')
        ->assertSet('selectionMaximum', '1');

    expect($sheet->options()->whereKey($target->id)->exists())->toBeFalse()
        ->and($sheet->options()->whereKey($retained->id)->exists())->toBeTrue()
        ->and($sheet->signups()->count())->toBe(2);
    Mail::assertQueuedCount(1);
});

test('other Account cannot confirm a post-mount Option deletion', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 2,
    ]);
    $target = $sheet->options()->create([
        'name' => 'Private target',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $retained = $sheet->options()->create([
        'name' => 'Retained Option',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 2,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Private participant',
        'email_snapshot' => 'private@example.test',
        'phone_snapshot' => '555-0102',
        'name_consent' => true,
        'email_consent' => true,
        'phone_consent' => true,
    ]);
    $claims = $signup->optionClaims()->createMany([
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
    $signupSnapshot = $signup->refresh()->only($snapshotFields);
    $optionState = collect([$target->refresh(), $retained->refresh()])
        ->map(fn ($option): array => $option->only([
            'id',
            'name',
            'capacity',
            'claimed_count',
            'position',
        ]))
        ->all();
    $component = Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->call('requestOptionDeletion', $target->id)
        ->assertSet('deletingOptionId', $target->id)
        ->assertSet('deletingOptionClaimCount', 1);

    $this->actingAs($otherAccount);

    $component->call('confirmOptionDeletion')->assertNotFound();

    expect($sheet->refresh()->selection_maximum)->toBe(2)
        ->and(collect([$target->refresh(), $retained->refresh()])->map(
            fn ($option): array => $option->only([
                'id',
                'name',
                'capacity',
                'claimed_count',
                'position',
            ]),
        )->all())->toBe($optionState)
        ->and(OptionClaim::query()->whereIn('id', $claims->pluck('id'))->count())->toBe(2)
        ->and($signup->refresh()->only($snapshotFields))->toBe($signupSnapshot);
    Mail::assertNothingQueued();
});

test('stale Published Option deletion confirmation fails safely', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 2,
    ]);
    $target = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $retained = $sheet->options()->create([
        'name' => 'Cleanup',
        'capacity' => 2,
        'position' => 2,
    ]);
    $signup = $sheet->signups()->create(['name_snapshot' => 'Participant']);
    $claim = $signup->optionClaims()->create(['option_id' => $target->id]);

    $component = Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->call('requestOptionDeletion', $target->id)
        ->assertSet('deletingOptionId', $target->id)
        ->assertSet('deletingOptionClaimCount', 1);

    app(DeleteOwnerOption::class)->handle($owner, $sheet, $target->id, 1);

    $component
        ->call('confirmOptionDeletion')
        ->assertHasErrors(['optionDeletion'])
        ->assertSee('This Option cannot be deleted.')
        ->assertSet('deletingOptionId', null)
        ->assertSet('deletingOptionClaimCount', 0)
        ->assertSet('announcement', 'This Option cannot be deleted.')
        ->assertSet('selectionMaximum', '1')
        ->assertDontSee('Option deleted.');

    expect(OptionClaim::query()->find($claim->id))->toBeNull()
        ->and($sheet->options()->whereKey($retained->id)->exists())->toBeTrue()
        ->and($sheet->refresh()->selection_maximum)->toBe(1);
});

test('Published capacity changes preserve claims and immediately govern new Signups', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Pie table',
        'capacity' => 1,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $existingSignup = $sheet->signups()->create(['name_snapshot' => 'Existing participant']);
    $existingClaim = $existingSignup->optionClaims()->create(['option_id' => $option->id]);

    Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->call('startEditingOption', $option->id)
        ->set('editOptionCapacity', '2')
        ->call('updateOption')
        ->assertHasNoErrors();

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSeeInOrder(['Pie table', 'Available', 'Total', '2', 'Claimed', '1', 'Remaining', '1']);

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'New participant')
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSee('Signup complete');

    $newSignup = $sheet->signups()->where('name_snapshot', 'New participant')->sole();
    $newClaim = $newSignup->optionClaims()->sole();

    Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet->refresh()])
        ->call('startEditingOption', $option->id)
        ->set('editOptionCapacity', '1')
        ->call('updateOption')
        ->assertHasNoErrors()
        ->assertSee('Over-Capacity — 1 over');

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSeeInOrder(['Pie table', 'Over capacity — unavailable', 'Total', '1', 'Claimed', '2', 'Remaining', '0']);

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Blocked participant')
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasErrors(['signup'])
        ->assertSee('Newly unavailable: Pie table');

    expect($sheet->signups()->count())->toBe(2)
        ->and(OptionClaim::query()->orderBy('id')->pluck('id')->all())
        ->toBe([$existingClaim->id, $newClaim->id])
        ->and($option->refresh()->claimed_count)->toBe(2);
});

test('Published Sheet selection maximum remains required and no greater than its Option count', function (string $selectionMaximum) {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $sheet->options()->create(['name' => 'Only Option', 'capacity' => 2, 'position' => 1]);

    Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->set('selectionMaximum', $selectionMaximum)
        ->call('saveDetails')
        ->assertHasErrors(['selectionMaximum']);

    Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet->refresh()])
        ->assertSet('selectionMaximum', '1');
})->with([
    'missing' => '',
    'greater than Option count' => '2',
]);

test('lowered Published selection maximum preserves over-limit Signup and permits only removal progress until compliant', function () {
    $owner = Account::factory()->create();
    $participant = Account::factory()->create(['email' => 'participant@example.com']);
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 3,
    ]);
    $options = collect(['First Claim', 'Second Claim', 'Third Claim'])->map(
        fn (string $name, int $index) => $sheet->options()->create([
            'name' => $name,
            'capacity' => 3,
            'claimed_count' => 1,
            'position' => $index + 1,
        ]),
    );
    $available = $sheet->options()->create([
        'name' => 'Available after compliance',
        'capacity' => 3,
        'position' => 4,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Participant Snapshot',
        'email_snapshot' => $participant->email,
    ]);
    $signup->account()->associate($participant);
    $signup->save();

    foreach ($options as $option) {
        $signup->optionClaims()->create(['option_id' => $option->id]);
    }

    $originalClaimIds = $signup->optionClaims()->orderBy('id')->pluck('id')->all();

    Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->set('selectionMaximum', '1')
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($signup->optionClaims()->orderBy('id')->pluck('id')->all())->toBe($originalClaimIds);

    $this->actingAs($owner)
        ->get(route('sheets.signups', $sheet))
        ->assertOk()
        ->assertSee('Over limit — 3 of 1 maximum');

    $component = Livewire::actingAs($participant)
        ->test('pages::signups.edit', ['signup' => $signup])
        ->assertSee('Signup over current limit')
        ->assertSee('Remove existing claims before adding another Option.')
        ->set('selectedOptions', [$options[0]->public_id, $options[1]->public_id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Signup over current limit');

    $retainedClaimIds = $signup->optionClaims()->orderBy('id')->pluck('id')->all();

    $component
        ->set('selectedOptions', [$options[0]->public_id, $options[1]->public_id, $available->public_id])
        ->call('save')
        ->assertHasErrors(['signup'])
        ->assertSee('Remove existing Option Claims before adding another Option.');

    expect($signup->optionClaims()->orderBy('id')->pluck('id')->all())->toBe($retainedClaimIds)
        ->and($available->refresh()->claimed_count)->toBe(0);

    $component
        ->set('selectedOptions', [$options[0]->public_id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDontSee('Signup over current limit')
        ->set('selectedOptions', [$available->public_id])
        ->call('save')
        ->assertHasNoErrors();

    expect($signup->refresh()->id)->toBe($signup->id)
        ->and($signup->optionClaims()->sole()->option_id)->toBe($available->id)
        ->and($available->refresh()->claimed_count)->toBe(1);
});

test('Published policy visibility and limits affect future completion without rewriting existing Signups', function () {
    $owner = Account::factory()->create();
    $verifiedParticipant = Account::factory()->create([
        'name' => 'Verified Participant',
        'email' => 'verified-participant@example.com',
    ]);
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 2,
        'name_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'email_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'phone_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
    ]);
    $reduced = $sheet->options()->create([
        'name' => 'Reduced capacity',
        'capacity' => 3,
        'claimed_count' => 2,
        'position' => 1,
    ]);
    $available = $sheet->options()->create([
        'name' => 'Future verified choice',
        'capacity' => 2,
        'position' => 2,
    ]);

    $existingSignups = collect(['First Existing', 'Second Existing'])->map(
        function (string $name) use ($sheet, $reduced) {
            $signup = $sheet->signups()->create([
                'name_snapshot' => $name,
                'email_snapshot' => null,
                'phone_snapshot' => '555-0100',
                'name_consent' => false,
                'email_consent' => false,
                'phone_consent' => false,
            ]);
            $signup->optionClaims()->create(['option_id' => $reduced->id]);

            return $signup;
        },
    );
    $existingSignupSnapshots = $existingSignups->map(fn ($signup) => [
        'id' => $signup->id,
        'attributes' => $signup->only([
            'name_snapshot',
            'email_snapshot',
            'phone_snapshot',
            'name_consent',
            'email_consent',
            'phone_consent',
        ]),
        'claim_ids' => $signup->optionClaims()->pluck('id')->all(),
    ])->all();

    Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->set('participationPolicy', Sheet::PARTICIPATION_VERIFIED)
        ->set('selectionMaximum', '1')
        ->set('nameVisibility', Sheet::VISIBILITY_PARTICIPANTS)
        ->set('emailVisibility', Sheet::VISIBILITY_PARTICIPANTS)
        ->set('phoneVisibility', Sheet::VISIBILITY_PARTICIPANTS)
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->call('startEditingOption', $reduced->id)
        ->set('editOptionCapacity', '1')
        ->call('updateOption')
        ->assertHasNoErrors();

    expect($sheet->signups()->orderBy('id')->get()->map(fn ($signup) => [
        'id' => $signup->id,
        'attributes' => $signup->only([
            'name_snapshot',
            'email_snapshot',
            'phone_snapshot',
            'name_consent',
            'email_consent',
            'phone_consent',
        ]),
        'claim_ids' => $signup->optionClaims()->pluck('id')->all(),
    ])->all())->toBe($existingSignupSnapshots);

    $input = fn (array $optionPublicIds) => new CompleteSignupInput(
        sheetPublicId: $sheet->public_id,
        name: 'Future Participant',
        phone: null,
        optionPublicIds: $optionPublicIds,
        email: $verifiedParticipant->email,
    );

    expect(fn () => app(CompleteOpenSignup::class)->handle($input([$available->public_id])))
        ->toThrow(CannotCompleteSignup::class, 'no longer open');
    expect(fn () => app(CompleteVerifiedSignup::class)->handle(
        $verifiedParticipant,
        $input([$reduced->public_id, $available->public_id]),
    ))->toThrow(CannotCompleteSignup::class, 'Choose between 1 and 1');
    expect(fn () => app(CompleteVerifiedSignup::class)->handle(
        $verifiedParticipant,
        $input([$reduced->public_id]),
    ))->toThrow(CannotCompleteSignup::class, 'became unavailable');

    app(CompleteVerifiedSignup::class)->handle(
        $verifiedParticipant,
        $input([$available->public_id]),
    );

    expect($sheet->signups()->count())->toBe(3)
        ->and($sheet->signups()->where('account_id', $verifiedParticipant->id)->sole()->optionClaims()->sole()->option_id)
        ->toBe($available->id)
        ->and($reduced->refresh()->claimed_count)->toBe(2)
        ->and($available->refresh()->claimed_count)->toBe(1);
});

test('other Account cannot view or mutate a Published Sheet editor', function () {
    $owner = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Owner Published Sheet',
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Owner Option',
        'capacity' => 2,
        'position' => 1,
    ]);

    $this->actingAs($otherAccount)
        ->get(route('sheets.edit', $sheet))
        ->assertNotFound()
        ->assertDontSee('Owner Published Sheet');

    $component = Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->set('title', 'Intruder title')
        ->call('startEditingOption', $option->id)
        ->set('editOptionCapacity', '99');

    $this->actingAs($otherAccount);
    $component->call('saveDetails')->assertStatus(404);

    expect($sheet->refresh()->title)->toBe('Owner Published Sheet')
        ->and($option->refresh()->capacity)->toBe(2);
});

test('other Account cannot invoke Owner Sheet lifecycle actions', function (string $action, string $initialState) {
    $this->travelTo(Carbon::parse('2026-08-01 19:00:00 UTC'));

    try {
        $owner = Account::factory()->create(['timezone' => 'America/Los_Angeles']);
        $otherAccount = Account::factory()->create(['timezone' => 'America/Los_Angeles']);
        $sheet = Sheet::factory()->for($owner, 'owner')->create([
            'state' => $initialState,
            'deadline_at' => Carbon::parse('2026-08-02 19:00:00 UTC'),
            'timezone' => 'America/Los_Angeles',
        ]);
        $originalDeadline = $sheet->deadline_at->toIso8601String();

        $component = Livewire::actingAs($owner)
            ->test('pages::sheets.edit', ['sheet' => $sheet]);

        if ($action === 'reopenSheet') {
            $component->set('deadlineAt', '2026-08-03T12:00');
        }

        $this->actingAs($otherAccount);
        $component
            ->call($action)
            ->assertStatus(404);

        expect($sheet->refresh())
            ->state->toBe($initialState)
            ->deadline_at->toIso8601String()->toBe($originalDeadline);
    } finally {
        $this->travelBack();
    }
})->with([
    'manual close' => ['closeSheet', Sheet::STATE_PUBLISHED],
    'future-deadline reopen' => ['reopenSheet', Sheet::STATE_CLOSED],
    'irreversible archive' => ['archiveSheet', Sheet::STATE_PUBLISHED],
]);

test('Published deadline edits immediately close and explicit reopen restores participant actions', function () {
    $this->travelTo(Carbon::parse('2026-08-01 12:00:00 UTC'));

    $owner = Account::factory()->create();
    $participant = Account::factory()->create(['email' => 'deadline-participant@example.com']);
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
        'deadline_at' => Carbon::parse('2026-08-10 23:59:00 America/Los_Angeles')->utc(),
    ]);
    $option = $sheet->options()->create([
        'name' => 'Deadline choice',
        'capacity' => 3,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Existing Participant',
        'email_snapshot' => $participant->email,
    ]);
    $signup->account()->associate($participant);
    $signup->save();
    $claim = $signup->optionClaims()->create(['option_id' => $option->id]);

    $participantComponent = Livewire::actingAs($participant)
        ->test('pages::signups.edit', ['signup' => $signup]);

    Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->set('deadlineAt', '2026-07-31T23:59')
        ->call('saveDetails')
        ->assertHasNoErrors();

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('Closed to signups');

    $this->actingAs($participant);
    $participantComponent
        ->set('name', 'Blocked Edit')
        ->assertStatus(404);

    expect(fn () => app(CompleteOpenSignup::class)->handle(new CompleteSignupInput(
        sheetPublicId: $sheet->public_id,
        name: 'Blocked Signup',
        phone: null,
        optionPublicIds: [$option->public_id],
    )))->toThrow(CannotCompleteSignup::class, 'no longer open');

    Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet->refresh()])
        ->set('deadlineAt', '2026-08-11T23:59')
        ->call('reopenSheet')
        ->assertHasNoErrors();

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'After Reopen')
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSee('Signup complete');

    expect($signup->refresh()->id)->toBe($signup->id)
        ->and($signup->optionClaims()->sole()->id)->toBe($claim->id)
        ->and($option->refresh()->claimed_count)->toBe(2);
});

test('expired Published Sheet is persisted Closed in the Owner editor until explicitly reopened', function () {
    $this->travelTo(Carbon::parse('2026-08-01 18:59:59 UTC'));

    $owner = Account::factory()->create(['timezone' => 'America/Los_Angeles']);
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Expired field day',
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
        'deadline_at' => Carbon::parse('2026-08-01 19:00:00 UTC'),
    ]);
    $option = $sheet->options()->create([
        'name' => 'Afternoon cleanup',
        'capacity' => 2,
        'position' => 1,
    ]);
    $participant = Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Blocked before explicit reopen')
        ->set('selectedOptions', [$option->public_id]);

    $this->travelTo(Carbon::parse('2026-08-01 19:00:00 UTC'));

    $editor = Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->assertSee('Closed Sheet')
        ->assertSee('Reopen Sheet')
        ->assertDontSee('Published Sheet')
        ->assertDontSee('Close Sheet');

    expect($sheet->refresh()->state)->toBe(Sheet::STATE_CLOSED);

    $editor
        ->set('title', 'Updated expired field day')
        ->set('deadlineAt', '2026-08-02T12:00')
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($sheet->refresh())
        ->title->toBe('Updated expired field day')
        ->state->toBe(Sheet::STATE_CLOSED)
        ->deadline_at->toIso8601String()->toBe('2026-08-02T19:00:00+00:00');

    $participant
        ->call('complete')
        ->assertHasErrors(['signup'])
        ->assertSee('This Signup Sheet is no longer open for signups.');

    $editor
        ->call('reopenSheet')
        ->assertHasNoErrors()
        ->assertSee('Signup Sheet reopened.');

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Allowed after explicit reopen')
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSee('Signup complete');

    expect($sheet->refresh()->state)->toBe(Sheet::STATE_PUBLISHED)
        ->and($sheet->signups()->count())->toBe(1);
});

test('Owner manually closes a Published Sheet before its deadline and new Signups are rejected', function () {
    $this->travelTo(Carbon::parse('2026-08-01 19:00:00 UTC'));

    $owner = Account::factory()->create(['timezone' => 'America/Los_Angeles']);
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Manual close field day',
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
        'deadline_at' => Carbon::parse('2026-08-02 19:00:00 UTC'),
    ]);
    $option = $sheet->options()->create([
        'name' => 'Morning setup',
        'capacity' => 2,
        'position' => 1,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->assertSee('Close Sheet')
        ->call('closeSheet')
        ->assertHasNoErrors()
        ->assertSee('Signup Sheet closed.');

    expect($sheet->refresh()->state)->toBe(Sheet::STATE_CLOSED);

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('Manual close field day')
        ->assertSee('Closed to signups')
        ->assertDontSee('Open for signups');

    expect(fn () => app(CompleteOpenSignup::class)->handle(new CompleteSignupInput(
        sheetPublicId: $sheet->public_id,
        name: 'Blocked participant',
        phone: null,
        optionPublicIds: [$option->public_id],
    )))->toThrow(CannotCompleteSignup::class, 'no longer open')
        ->and($sheet->signups()->count())->toBe(0);
});

test('Owner reopens a Closed Sheet with a future deadline in their timezone', function () {
    $this->travelTo(Carbon::parse('2026-08-01 19:00:00 UTC'));

    $owner = Account::factory()->create(['timezone' => 'America/Los_Angeles']);
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Reopened field day',
        'state' => Sheet::STATE_CLOSED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
        'deadline_at' => Carbon::parse('2026-07-31 19:00:00 UTC'),
    ]);
    $option = $sheet->options()->create([
        'name' => 'Afternoon cleanup',
        'capacity' => 2,
        'position' => 1,
    ]);

    Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->assertSee('Closed Sheet')
        ->assertSee('Reopen Sheet')
        ->set('deadlineAt', '2026-08-02T12:00')
        ->call('reopenSheet')
        ->assertHasNoErrors()
        ->assertSee('Signup Sheet reopened.');

    expect($sheet->refresh())
        ->state->toBe(Sheet::STATE_PUBLISHED)
        ->deadline_at->toIso8601String()->toBe('2026-08-02T19:00:00+00:00');

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('Open for signups')
        ->assertDontSee('Closed to signups');

    app(CompleteOpenSignup::class)->handle(new CompleteSignupInput(
        sheetPublicId: $sheet->public_id,
        name: 'After reopen',
        phone: null,
        optionPublicIds: [$option->public_id],
    ));

    expect($sheet->signups()->count())->toBe(1);
});

test('Owner irreversibly archives a Published Sheet and its public UUID becomes generically unavailable', function () {
    $this->travelTo(Carbon::parse('2026-08-01 19:00:00 UTC'));

    $owner = Account::factory()->create(['timezone' => 'America/Los_Angeles']);
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Private archived harvest details',
        'description' => 'Private participant planning notes',
        'state' => Sheet::STATE_PUBLISHED,
        'deadline_at' => Carbon::parse('2026-08-02 19:00:00 UTC'),
    ]);

    Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->assertSee('Archive Sheet')
        ->assertSeeHtml('wire:confirm="Archive this Signup Sheet? This cannot be undone."')
        ->call('archiveSheet')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect($sheet->refresh()->state)->toBe(Sheet::STATE_ARCHIVED);

    $archivedResponse = $this->get(route('sheets.show', $sheet));
    $unknownResponse = $this->get('/sheets/00000000-0000-4000-8000-000000000000');

    $archivedResponse
        ->assertNotFound()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('This signup sheet is unavailable.')
        ->assertDontSee('Private archived harvest details')
        ->assertDontSee('Private participant planning notes');
    $unknownResponse
        ->assertNotFound()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('This signup sheet is unavailable.');

    expect($archivedResponse->getContent())->toBe($unknownResponse->getContent());

    $this->get(route('sheets.edit', $sheet))->assertNotFound();
});
