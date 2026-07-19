<?php

use App\Actions\CompleteVerifiedSignup;
use App\Data\CompleteSignupInput;
use App\Exceptions\CannotCompleteSignup;
use App\Http\Controllers\AccountAccessController;
use App\Mail\AccountAccessMail;
use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\PendingAccountAssociation;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

test('Unauthenticated Participant enters Verified Participation through passwordless access', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Verified welcome table',
        'capacity' => 1,
        'position' => 1,
    ]);

    $participationUrl = route('sheets.participate', $sheet);

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('Verified Participation')
        ->assertSee('Verify your email before choosing Options')
        ->assertSeeHtml('href="'.$participationUrl.'"')
        ->assertDontSeeHtml('wire:submit="complete"');

    $this->get($participationUrl)
        ->assertRedirect(route('login'))
        ->assertSessionHas('url.intended', $participationUrl);

    expect($sheet->signups()->count())->toBe(0)
        ->and($option->refresh()->claimed_count)->toBe(0);
});

test('New passwordless Participant returns to Verified Participation after completing Account profile', function () {
    Mail::fake();

    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 1,
    ]);
    $sheet->options()->create([
        'name' => 'First Account choice',
        'capacity' => 1,
        'position' => 1,
    ]);
    $participationUrl = route('sheets.participate', $sheet);

    $this->get($participationUrl)->assertRedirect(route('login'));
    $this->post(route('account-access.request'), ['email' => 'new-participant@example.com'])
        ->assertRedirect(route('login'));

    /** @var AccountAccessMail $mail */
    $mail = Mail::queued(AccountAccessMail::class)->sole();
    preg_match('/\b(\d{6})\b/', strip_tags($mail->render()), $codeMatch);

    $this->post(route('account-access.code'), ['code' => $codeMatch[1] ?? ''])
        ->assertRedirect($participationUrl);

    $this->get($participationUrl)
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('url.intended', $participationUrl);

    Livewire::test('pages::settings.profile')
        ->set('name', 'New Participant')
        ->set('timezone', 'America/Los_Angeles')
        ->call('updateProfileInformation')
        ->assertHasNoErrors()
        ->assertRedirect($participationUrl);

    $this->get($participationUrl)
        ->assertOk()
        ->assertSee('First Account choice')
        ->assertSee('new-participant@example.com');
});

test('Verified Account completes one capacity-bearing Signup', function () {
    $account = Account::factory()->create([
        'name' => 'Account Default',
        'email' => 'verified@example.com',
        'phone' => '555-0100',
    ]);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Last verified shift',
        'capacity' => 2,
        'position' => 1,
    ]);

    Livewire::actingAs($account)
        ->test('complete-verified-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', '  Signup Snapshot  ')
        ->set('phone', '')
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSee('Signup complete');

    $signup = Signup::query()->with('optionClaims')->sole();

    expect($signup)
        ->account_id->toBe($account->id)
        ->name_snapshot->toBe('Signup Snapshot')
        ->email_snapshot->toBe('verified@example.com')
        ->phone_snapshot->toBeNull()
        ->and($signup->optionClaims)->toHaveCount(1)
        ->and(PendingAccountAssociation::query()->count())->toBe(0)
        ->and($option->refresh()->claimed_count)->toBe(1);
});

test('future verified Account Signup persists only consent for participant-visible fields', function () {
    $account = Account::factory()->create([
        'name' => 'Changed Account Default',
        'email' => 'verified-privacy@example.test',
        'phone' => '555-9999',
    ]);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 1,
        'name_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'email_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'phone_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Verified privacy Option',
        'capacity' => 1,
        'position' => 1,
    ]);

    Livewire::actingAs($account)
        ->test('complete-verified-signup', ['sheetPublicId' => $sheet->public_id])
        ->assertSee('Visibility Consent')
        ->assertSee('Share full name')
        ->assertDontSee('Share email')
        ->assertSee('Share phone')
        ->set('name', 'Verified Signup Snapshot')
        ->set('phone', '555-0124')
        ->set('nameConsent', true)
        ->set('emailConsent', true)
        ->set('phoneConsent', true)
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasNoErrors();

    expect(Signup::query()->sole())
        ->name_consent->toBeTrue()
        ->email_consent->toBeFalse()
        ->phone_consent->toBeTrue();

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('Verified Signup Snapshot')
        ->assertSee('555-0124')
        ->assertDontSee('Changed Account Default')
        ->assertDontSee('555-9999');
});

test('Verified Signup attempts are throttled independently per Sheet and Account', function () {
    $account = Account::factory()->create([
        'name' => 'Limited Participant',
        'email' => 'limited@example.com',
    ]);
    $otherAccount = Account::factory()->create([
        'name' => 'Other Participant',
        'email' => 'other@example.com',
    ]);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Rate-limited choice',
        'capacity' => 3,
        'position' => 1,
    ]);

    $component = Livewire::actingAs($account)
        ->test('complete-verified-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('selectedOptions', [$option->public_id]);

    foreach (range(1, 5) as $attempt) {
        $component
            ->call('complete')
            ->assertHasNoErrors();
    }

    $component
        ->call('complete')
        ->assertHasErrors(['signup'])
        ->assertSet('announcement', 'Too many signup attempts. Please wait a minute and try again.')
        ->assertSee('Too many signup attempts');

    Livewire::actingAs($otherAccount)
        ->test('complete-verified-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasNoErrors();

    $otherSheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 1,
    ]);
    $otherOption = $otherSheet->options()->create([
        'name' => 'Other Sheet choice',
        'capacity' => 1,
        'position' => 1,
    ]);

    Livewire::actingAs($account)
        ->test('complete-verified-signup', ['sheetPublicId' => $otherSheet->public_id])
        ->set('selectedOptions', [$otherOption->public_id])
        ->call('complete')
        ->assertHasNoErrors();
});

test('Verified Participation Signup throttle emits one privacy-safe structured warning without side effects', function () {
    Mail::fake();
    $account = Account::factory()->create([
        'name' => 'Private Participant',
        'email' => 'private@example.test',
        'phone' => '555-0199',
    ]);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Popular table',
        'capacity' => 2,
        'position' => 1,
    ]);
    $component = Livewire::actingAs($account)
        ->test('complete-verified-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('selectedOptions', [$option->public_id]);

    foreach (range(1, 5) as $attempt) {
        $component
            ->call('complete')
            ->assertHasNoErrors();
    }

    $accountCount = Account::query()->count();
    $signupCount = Signup::query()->count();
    $claimCount = OptionClaim::query()->count();
    Log::spy();

    $component
        ->call('complete')
        ->assertHasErrors(['signup'])
        ->assertSet('announcement', 'Too many signup attempts. Please wait a minute and try again.')
        ->assertSee('Too many signup attempts');

    Log::shouldHaveReceived('warning')
        ->with('signup.throttled', [
            'operation' => 'submission',
            'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
            'sheet_public_id' => $sheet->public_id,
        ])
        ->once();
    expect(Account::query()->count())->toBe($accountCount)
        ->and(Signup::query()->count())->toBe($signupCount)
        ->and(OptionClaim::query()->count())->toBe($claimCount)
        ->and($option->refresh()->claimed_count)->toBe(1);
    Mail::assertNothingQueued();
});

test('Returning verified Participant reaches its existing Signup instead of a duplicate form', function () {
    $account = Account::factory()->create([
        'name' => 'Returning Participant',
        'email' => 'returning@example.com',
    ]);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Existing verified choice',
        'capacity' => 2,
        'position' => 1,
    ]);

    Livewire::actingAs($account)
        ->test('complete-verified-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasNoErrors();

    Livewire::actingAs($account)
        ->test('complete-verified-signup', ['sheetPublicId' => $sheet->public_id])
        ->assertSet('existingSignup', true)
        ->assertSee('Your Signup is ready')
        ->assertSee('Existing verified choice')
        ->assertDontSee('Complete Signup');

    expect($sheet->signups()->count())->toBe(1)
        ->and($option->refresh()->claimed_count)->toBe(1);
});

test('Verified policy transition attaches a matching Pending Account Association and preserves its Signup', function () {
    $account = Account::factory()->create([
        'email' => 'policy-transition@example.com',
    ]);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $claimedOption = $sheet->options()->create([
        'name' => 'Original Open choice',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $unselectedOption = $sheet->options()->create([
        'name' => 'Unselected Verified choice',
        'capacity' => 2,
        'position' => 2,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Original Snapshot',
        'email_snapshot' => 'policy-transition@example.com',
        'phone_snapshot' => '555-0147',
        'name_consent' => true,
        'email_consent' => false,
        'phone_consent' => true,
    ]);
    $association = $signup->pendingAccountAssociation()->create([
        'account_id' => $account->id,
    ]);
    $claim = $signup->optionClaims()->create(['option_id' => $claimedOption->id]);
    $snapshot = $signup->only([
        'name_snapshot',
        'email_snapshot',
        'phone_snapshot',
        'name_consent',
        'email_consent',
        'phone_consent',
    ]);
    $sheet->update(['participation_policy' => Sheet::PARTICIPATION_VERIFIED]);

    app(CompleteVerifiedSignup::class)->handle($account, new CompleteSignupInput(
        sheetPublicId: $sheet->public_id,
        name: 'Replacement Snapshot',
        phone: '555-9999',
        optionPublicIds: [$unselectedOption->public_id],
        email: $account->email,
    ));

    expect($sheet->signups()->count())->toBe(1)
        ->and($signup->refresh()->account_id)->toBe($account->id)
        ->and($signup->only(array_keys($snapshot)))->toBe($snapshot)
        ->and($signup->optionClaims()->pluck('id')->all())->toBe([$claim->id])
        ->and(PendingAccountAssociation::query()->find($association->id))->toBeNull()
        ->and($claimedOption->refresh()->claimed_count)->toBe(1)
        ->and($unselectedOption->refresh()->claimed_count)->toBe(0);
});

test('Verified Participation mount resolves a matching Pending Account Association as the existing Signup', function () {
    $account = Account::factory()->create([
        'email' => 'mount-transition@example.com',
    ]);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Preserved mount choice',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Mount Snapshot',
        'email_snapshot' => $account->email,
    ]);
    $association = $signup->pendingAccountAssociation()->create([
        'account_id' => $account->id,
    ]);
    $claim = $signup->optionClaims()->create(['option_id' => $option->id]);

    Livewire::actingAs($account)
        ->test('complete-verified-signup', ['sheetPublicId' => $sheet->public_id])
        ->assertSet('existingSignup', true)
        ->assertSee('Your Signup is ready')
        ->assertSee('Preserved mount choice')
        ->assertDontSee('Complete Signup');

    expect($signup->refresh()->account_id)->toBe($account->id)
        ->and($signup->optionClaims()->pluck('id')->all())->toBe([$claim->id])
        ->and(PendingAccountAssociation::query()->find($association->id))->toBeNull()
        ->and($option->refresh()->claimed_count)->toBe(1);
});

test('Unverified Account cannot reach or invoke Verified Participation completion', function () {
    $account = Account::factory()->unverified()->create([
        'email' => 'unverified@example.com',
    ]);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Protected capacity',
        'capacity' => 1,
        'position' => 1,
    ]);

    $this->actingAs($account)
        ->get(route('sheets.participate', $sheet))
        ->assertRedirect(route('verification.notice'));

    expect(fn () => app(CompleteVerifiedSignup::class)->handle(
        $account,
        new CompleteSignupInput(
            sheetPublicId: $sheet->public_id,
            name: 'Unverified Participant',
            phone: null,
            optionPublicIds: [$option->public_id],
            email: $account->email,
        ),
    ))->toThrow(CannotCompleteSignup::class, 'Verify your Account email');

    expect($sheet->signups()->count())->toBe(0)
        ->and($option->refresh()->claimed_count)->toBe(0);
});

test('Verified completion requires the authenticated Account email and Verified policy', function (array $changes) {
    $account = Account::factory()->create(['email' => 'identity@example.com']);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Server checked Option',
        'capacity' => 1,
        'position' => 1,
    ]);

    $sheet->update($changes);

    expect(fn () => app(CompleteVerifiedSignup::class)->handle(
        $account,
        new CompleteSignupInput(
            sheetPublicId: $sheet->public_id,
            name: 'Verified Participant',
            phone: null,
            optionPublicIds: [$option->public_id],
            email: $changes === [] ? 'different@example.com' : $account->email,
        ),
    ))->toThrow(CannotCompleteSignup::class);

    expect($sheet->signups()->count())->toBe(0)
        ->and($option->refresh()->claimed_count)->toBe(0);
})->with([
    'different email' => [[]],
    'Open Participation Sheet' => [['participation_policy' => Sheet::PARTICIPATION_OPEN]],
]);

test('Returning Participant reaches the existing Signup after neutral passwordless access', function () {
    Mail::fake();

    $account = Account::factory()->passwordless()->create([
        'name' => 'Passwordless Participant',
        'email' => 'passwordless@example.com',
    ]);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Passwordless existing choice',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Passwordless Participant',
        'email_snapshot' => $account->email,
    ]);
    $signup->account()->associate($account);
    $signup->save();
    $signup->optionClaims()->create(['option_id' => $option->id]);
    $participationUrl = route('sheets.participate', $sheet);

    $this->get($participationUrl)
        ->assertRedirect(route('login'))
        ->assertSessionHas('url.intended', $participationUrl);

    $this->post(route('account-access.request'), ['email' => ' PASSWORDLESS@EXAMPLE.COM '])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', AccountAccessController::NEUTRAL_STATUS)
        ->assertSessionMissing('existing_signup');

    /** @var AccountAccessMail $mail */
    $mail = Mail::queued(AccountAccessMail::class)->sole();
    preg_match('/\b(\d{6})\b/', strip_tags($mail->render()), $codeMatch);

    $this->post(route('account-access.code'), ['code' => $codeMatch[1] ?? ''])
        ->assertRedirect($participationUrl);

    $this->get($participationUrl)
        ->assertOk()
        ->assertSee('Your Signup is ready')
        ->assertSee('Passwordless existing choice')
        ->assertDontSee('Complete Signup');

    expect($sheet->signups()->count())->toBe(1)
        ->and($option->refresh()->claimed_count)->toBe(1);
});

test('Owner participates in its own Verified Signup Sheet through the participant flow', function () {
    $owner = Account::factory()->create([
        'name' => 'Participating Owner',
        'email' => 'owner-participant@example.com',
    ]);
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Owner choice',
        'capacity' => 1,
        'position' => 1,
    ]);

    Livewire::actingAs($owner)
        ->test('complete-verified-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSee('Signup complete');

    expect($sheet->signups()->sole()->account_id)->toBe($owner->id)
        ->and($option->refresh()->claimed_count)->toBe(1);
});

test('Verified capacity race preserves recoverable input and identifies unavailable Options', function () {
    $account = Account::factory()->create([
        'name' => 'Capacity Participant',
        'email' => 'capacity@example.com',
        'phone' => '555-0198',
    ]);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 2,
    ]);
    $finalOption = $sheet->options()->create([
        'name' => 'Newly full verified Option',
        'capacity' => 1,
        'position' => 1,
    ]);
    $availableOption = $sheet->options()->create([
        'name' => 'Still available verified Option',
        'capacity' => 2,
        'position' => 2,
    ]);
    $component = Livewire::actingAs($account)
        ->test('complete-verified-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Capacity Snapshot')
        ->set('phone', '555-0177')
        ->set('selectedOptions', [$finalOption->public_id, $availableOption->public_id]);

    $finalOption->update(['claimed_count' => 1]);

    $component
        ->call('complete')
        ->assertHasErrors(['signup'])
        ->assertSee('Newly unavailable: Newly full verified Option')
        ->assertSet('name', 'Capacity Snapshot')
        ->assertSet('email', 'capacity@example.com')
        ->assertSet('phone', '555-0177')
        ->assertSet('selectedOptions', [$availableOption->public_id]);

    expect($sheet->signups()->count())->toBe(0)
        ->and($availableOption->refresh()->claimed_count)->toBe(0);
});

test('Verified Account reaches a Signup initialized from Account Defaults', function () {
    $account = Account::factory()->create([
        'name' => 'Avery Account',
        'email' => 'avery@example.com',
        'phone' => '555-0142',
    ]);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Verified setup',
        'capacity' => 2,
        'position' => 1,
    ]);

    $this->actingAs($account)
        ->get(route('sheets.participate', $sheet))
        ->assertOk()
        ->assertSee('Verified setup')
        ->assertSeeHtml('wire:name="complete-verified-signup"');

    Livewire::actingAs($account)
        ->test('complete-verified-signup', ['sheetPublicId' => $sheet->public_id])
        ->assertSet('name', 'Avery Account')
        ->assertSet('email', 'avery@example.com')
        ->assertSet('phone', '555-0142')
        ->assertSee('Account email')
        ->assertSee('Verified setup')
        ->assertSeeHtml('value="'.$option->public_id.'"');
});

test('Authenticated participation route is one noindex document', function () {
    $account = Account::factory()->create();
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_VERIFIED,
        'selection_maximum' => 1,
    ]);
    $sheet->options()->create([
        'name' => 'Document Option',
        'capacity' => 1,
        'position' => 1,
    ]);

    $response = $this->actingAs($account)->get(route('sheets.participate', $sheet));

    $response
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSeeHtml('<meta name="robots" content="noindex, nofollow">');

    expect(substr_count($response->getContent(), '<!DOCTYPE html>'))->toBe(1);
});
