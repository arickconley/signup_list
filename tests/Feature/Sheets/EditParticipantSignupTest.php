<?php

use App\Models\Account;
use App\Models\Sheet;
use App\Models\Signup;
use Livewire\Livewire;

function issue14ParticipantUiSignup(Account $account): Signup
{
    $sheet = Sheet::factory()->create([
        'title' => 'Community Work Day',
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 2,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Signup Name',
        'email_snapshot' => $account->email,
        'phone_snapshot' => '555-0142',
    ]);
    $signup->forceFill(['account_id' => $account->id])->save();

    return $signup;
}

test('participant Signup edit route requires authentication verification and a complete Account profile', function (string $case) {
    $account = match ($case) {
        'guest' => Account::factory()->create(),
        'unverified' => Account::factory()->unverified()->create(),
        'incomplete profile' => Account::factory()->create(['timezone' => null]),
    };
    $signup = issue14ParticipantUiSignup($account);

    if ($case !== 'guest') {
        $this->actingAs($account);
    }

    $response = $this->get(route('signups.edit', $signup));

    match ($case) {
        'guest' => $response->assertRedirect(route('login')),
        'unverified' => $response->assertRedirect(route('verification.notice')),
        'incomplete profile' => $response->assertRedirect(route('profile.edit')),
    };
})->with(['guest', 'unverified', 'incomplete profile']);

test('associated verified Account opens its participant Signup edit route', function () {
    $account = Account::factory()->create();
    $signup = issue14ParticipantUiSignup($account);

    $this->actingAs($account)
        ->get(route('signups.edit', $signup))
        ->assertOk()
        ->assertSee('Edit Signup')
        ->assertSee('Community Work Day');
});

test('participant Signup edit document is noindex nofollow', function () {
    $account = Account::factory()->create();
    $signup = issue14ParticipantUiSignup($account);

    $this->actingAs($account)
        ->get(route('signups.edit', $signup))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSeeHtml('<meta name="robots" content="noindex, nofollow">');
});

test('another Account receives not found for participant Signup editing', function () {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $signup = issue14ParticipantUiSignup($account);

    $this->actingAs($otherAccount)
        ->get(route('signups.edit', $signup))
        ->assertNotFound();
});

test('participant edit form initializes immutable Account email details consent and every current Option Claim', function () {
    $account = Account::factory()->create(['email' => 'participant@example.com']);
    $signup = issue14ParticipantUiSignup($account);
    $signup->sheet->update([
        'name_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'email_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'phone_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
    ]);
    $signup->update([
        'name_snapshot' => 'Visible Participant',
        'phone_snapshot' => '555-0198',
        'name_consent' => true,
        'email_consent' => false,
        'phone_consent' => true,
    ]);
    $full = $signup->sheet->options()->create([
        'name' => 'Retained Full Option',
        'capacity' => 1,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $overCapacity = $signup->sheet->options()->create([
        'name' => 'Retained Over Capacity Option',
        'capacity' => 1,
        'claimed_count' => 2,
        'position' => 2,
    ]);
    $available = $signup->sheet->options()->create([
        'name' => 'Available Option',
        'capacity' => 2,
        'position' => 3,
    ]);
    $signup->optionClaims()->createMany([
        ['option_id' => $full->id],
        ['option_id' => $overCapacity->id],
    ]);
    $otherSignup = $signup->sheet->signups()->create(['name_snapshot' => 'Other Participant']);
    $otherSignup->optionClaims()->create(['option_id' => $overCapacity->id]);

    Livewire::actingAs($account)
        ->test('pages::signups.edit', ['signup' => $signup])
        ->assertSet('email', 'participant@example.com')
        ->assertSet('name', 'Visible Participant')
        ->assertSet('phone', '555-0198')
        ->assertSet('nameConsent', true)
        ->assertSet('emailConsent', false)
        ->assertSet('phoneConsent', true)
        ->assertSet('selectedOptions', [$full->public_id, $overCapacity->public_id])
        ->assertSeeHtml('id="participant-email"')
        ->assertSeeHtml('readonly')
        ->assertSee('Retained Full Option')
        ->assertSee('Currently claimed — full')
        ->assertSee('Retained Over Capacity Option')
        ->assertSee('Currently claimed — over capacity')
        ->assertSee($available->name);
});

test('participant edit renders consent only for participant-visible fields', function () {
    $account = Account::factory()->create();
    $signup = issue14ParticipantUiSignup($account);
    $signup->sheet->update([
        'name_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'email_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'phone_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
    ]);

    Livewire::actingAs($account)
        ->test('pages::signups.edit', ['signup' => $signup])
        ->assertSee('Visibility Consent')
        ->assertSee('Share full name')
        ->assertDontSee('Share email')
        ->assertSee('Share phone');
});

test('participant saves editable details consent and Option Claims while email identity remains unchanged', function () {
    $account = Account::factory()->create(['email' => 'fixed@example.com']);
    $signup = issue14ParticipantUiSignup($account);
    $signup->sheet->update([
        'name_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'email_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'phone_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
    ]);
    $current = $signup->sheet->options()->create([
        'name' => 'Current Option',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $replacement = $signup->sheet->options()->create([
        'name' => 'Replacement Option',
        'capacity' => 2,
        'position' => 2,
    ]);
    $signup->optionClaims()->create(['option_id' => $current->id]);

    Livewire::actingAs($account)
        ->test('pages::signups.edit', ['signup' => $signup])
        ->set('name', 'Updated Signup Name')
        ->set('phone', '555-0109')
        ->set('nameConsent', true)
        ->set('emailConsent', true)
        ->set('phoneConsent', false)
        ->set('selectedOptions', [$replacement->public_id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('announcement', 'Signup saved.')
        ->assertSet('email', 'fixed@example.com')
        ->assertSet('currentOptionPublicIds', [$replacement->public_id])
        ->assertSee('Signup saved.');

    expect($signup->refresh())
        ->name_snapshot->toBe('Updated Signup Name')
        ->email_snapshot->toBe('fixed@example.com')
        ->phone_snapshot->toBe('555-0109')
        ->name_consent->toBeTrue()
        ->email_consent->toBeTrue()
        ->phone_consent->toBeFalse()
        ->and($signup->optionClaims()->pluck('option_id')->all())->toBe([$replacement->id])
        ->and($current->refresh()->claimed_count)->toBe(0)
        ->and($replacement->refresh()->claimed_count)->toBe(1);
});

test('recoverable validation errors are summarized inline without losing participant input', function () {
    $account = Account::factory()->create();
    $signup = issue14ParticipantUiSignup($account);
    $option = $signup->sheet->options()->create([
        'name' => 'Selected Option',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup->optionClaims()->create(['option_id' => $option->id]);
    $longPhone = str_repeat('5', 51);

    Livewire::actingAs($account)
        ->test('pages::signups.edit', ['signup' => $signup])
        ->set('name', 'Preserved Name')
        ->set('phone', $longPhone)
        ->set('nameConsent', true)
        ->set('emailConsent', true)
        ->set('phoneConsent', true)
        ->call('save')
        ->assertHasErrors(['phone'])
        ->assertSet('name', 'Preserved Name')
        ->assertSet('phone', $longPhone)
        ->assertSet('nameConsent', true)
        ->assertSet('emailConsent', true)
        ->assertSet('phoneConsent', true)
        ->assertSet('selectedOptions', [$option->public_id])
        ->assertSee('Please correct the highlighted fields.')
        ->assertSeeHtml('id="phone-error"');
});

test('capacity race preserves input and removes only the newly unavailable selection', function () {
    $account = Account::factory()->create();
    $signup = issue14ParticipantUiSignup($account);
    $signup->sheet->update(['selection_maximum' => 3]);
    $current = $signup->sheet->options()->create([
        'name' => 'Current Claim',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $racing = $signup->sheet->options()->create([
        'name' => 'Just Filled',
        'capacity' => 1,
        'position' => 2,
    ]);
    $stillAvailable = $signup->sheet->options()->create([
        'name' => 'Still Available',
        'capacity' => 2,
        'position' => 3,
    ]);
    $signup->optionClaims()->create(['option_id' => $current->id]);

    $component = Livewire::actingAs($account)
        ->test('pages::signups.edit', ['signup' => $signup])
        ->set('name', 'Preserved Race Name')
        ->set('phone', '555-0177')
        ->set('nameConsent', true)
        ->set('emailConsent', true)
        ->set('phoneConsent', true)
        ->set('selectedOptions', [
            $current->public_id,
            $racing->public_id,
            $stillAvailable->public_id,
        ]);

    $otherSignup = $signup->sheet->signups()->create(['name_snapshot' => 'Race Winner']);
    $otherSignup->optionClaims()->create(['option_id' => $racing->id]);
    $racing->update(['claimed_count' => 1]);

    $component
        ->call('save')
        ->assertHasErrors(['signup'])
        ->assertSet('name', 'Preserved Race Name')
        ->assertSet('phone', '555-0177')
        ->assertSet('nameConsent', true)
        ->assertSet('emailConsent', true)
        ->assertSet('phoneConsent', true)
        ->assertSet('selectedOptions', [
            $current->public_id,
            $stillAvailable->public_id,
        ])
        ->assertSet('currentOptionPublicIds', [$current->public_id])
        ->assertSet('announcement', 'Some selected Options just became unavailable. Choose another Option and try again.')
        ->assertSee('Please correct the highlighted fields.')
        ->assertSeeHtml('id="participant-options-error"')
        ->assertSee('Newly unavailable: Just Filled');

    expect($signup->refresh())
        ->name_snapshot->toBe('Signup Name')
        ->phone_snapshot->toBe('555-0142')
        ->and($signup->optionClaims()->pluck('option_id')->all())->toBe([$current->id])
        ->and($current->refresh()->claimed_count)->toBe(1)
        ->and($racing->refresh()->claimed_count)->toBe(1)
        ->and($stillAvailable->refresh()->claimed_count)->toBe(0);
});

test('over-limit participant removes claims while additions remain unavailable until compliant', function () {
    $account = Account::factory()->create();
    $signup = issue14ParticipantUiSignup($account);
    $signup->sheet->update(['selection_maximum' => 2]);
    $claimedOptions = collect(range(1, 4))->map(fn (int $position) => $signup->sheet->options()->create([
        'name' => 'Existing Claim '.$position,
        'capacity' => 5,
        'claimed_count' => 1,
        'position' => $position,
    ]));
    $available = $signup->sheet->options()->create([
        'name' => 'Blocked Addition',
        'capacity' => 5,
        'position' => 5,
    ]);
    $signup->optionClaims()->createMany($claimedOptions
        ->map(fn ($option): array => ['option_id' => $option->id])
        ->all());
    $retainedPublicIds = $claimedOptions
        ->take(3)
        ->pluck('public_id')
        ->all();
    $removed = $claimedOptions->last();

    Livewire::actingAs($account)
        ->test('pages::signups.edit', ['signup' => $signup])
        ->assertSet('selectedOptions', $claimedOptions->pluck('public_id')->all())
        ->assertSee('Signup over current limit')
        ->assertSee('Remove existing claims before adding another Option.')
        ->set('selectedOptions', $retainedPublicIds)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('currentOptionPublicIds', $retainedPublicIds)
        ->assertSet('announcement', 'Signup saved.')
        ->assertSee('Signup over current limit')
        ->assertSee('Remove existing claims before adding another Option.');

    expect($signup->optionClaims()->orderBy('option_id')->pluck('option_id')->all())
        ->toBe($claimedOptions->take(3)->pluck('id')->all())
        ->and($removed->refresh()->claimed_count)->toBe(0)
        ->and($available->refresh()->claimed_count)->toBe(0);
});

test('participant explicitly confirms cancellation and returns to Dashboard after releasing all claims', function () {
    $account = Account::factory()->create();
    $signup = issue14ParticipantUiSignup($account);
    $first = $signup->sheet->options()->create([
        'name' => 'First cancelled claim',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $second = $signup->sheet->options()->create([
        'name' => 'Second cancelled claim',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 2,
    ]);
    $signup->optionClaims()->createMany([
        ['option_id' => $first->id],
        ['option_id' => $second->id],
    ]);

    Livewire::actingAs($account)
        ->test('pages::signups.edit', ['signup' => $signup])
        ->assertSee('Cancel Signup')
        ->assertSeeHtml('id="cancel-signup-description"')
        ->assertSeeHtml('wire:confirm')
        ->call('cancel')
        ->assertRedirect(route('dashboard'));

    expect(Signup::query()->find($signup->id))->toBeNull()
        ->and($first->refresh()->claimed_count)->toBe(0)
        ->and($second->refresh()->claimed_count)->toBe(0);
});
