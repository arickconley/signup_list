<?php

use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

test('Sheet lifecycle predicates honor manual state and the exact deadline boundary', function (
    string $state,
    string $deadline,
    array $expected,
) {
    $this->travelTo(Carbon::parse('2026-08-01 19:00:00 UTC'));

    $owner = Account::factory()->create(['timezone' => 'America/Los_Angeles']);
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => $state,
        'deadline_at' => Carbon::parse($deadline),
    ]);

    expect([
        'open' => $sheet->isOpen(),
        'closed' => $sheet->isClosed(),
        'archived' => $sheet->isArchived(),
        'publicly_viewable' => $sheet->isPubliclyViewable(),
    ])->toBe($expected);
})->with([
    'Published Sheet before deadline' => [
        Sheet::STATE_PUBLISHED,
        '2026-08-01 19:00:01 UTC',
        ['open' => true, 'closed' => false, 'archived' => false, 'publicly_viewable' => true],
    ],
    'deadline boundary' => [
        Sheet::STATE_PUBLISHED,
        '2026-08-01 19:00:00 UTC',
        ['open' => false, 'closed' => true, 'archived' => false, 'publicly_viewable' => true],
    ],
    'manually Closed Sheet' => [
        'closed',
        '2026-08-01 20:00:00 UTC',
        ['open' => false, 'closed' => true, 'archived' => false, 'publicly_viewable' => true],
    ],
    'Archived Sheet' => [
        Sheet::STATE_ARCHIVED,
        '2026-08-01 20:00:00 UTC',
        ['open' => false, 'closed' => false, 'archived' => true, 'publicly_viewable' => false],
    ],
]);

test('Signup Sheet acceptance requires Published Open Participation before its deadline', function (array $attributes, bool $expected) {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'deadline_at' => now()->addHour(),
        ...$attributes,
    ]);

    expect($sheet->isAcceptingOpenParticipationSignups())->toBe($expected);
})->with([
    'accepting' => [[], true],
    'Draft Sheet' => [['state' => Sheet::STATE_DRAFT], false],
    'Verified Participation' => [['participation_policy' => 'verified'], false],
    'past deadline' => [['deadline_at' => now()->subSecond()], false],
]);

test('card checkbox supports an accessible id and Option value', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.checkbox
            id="signup-option-choice"
            name="selectedOptions"
            value="0198a24e-13d4-73b8-a54e-6d68e290c834"
            label="Morning setup"
            variant="card"
        />
        BLADE);

    expect(str_contains($html, 'for="signup-option-choice"'))->toBeTrue()
        ->and(str_contains($html, 'id="signup-option-choice"'))->toBeTrue()
        ->and(str_contains($html, 'value="0198a24e-13d4-73b8-a54e-6d68e290c834"'))->toBeTrue()
        ->and(str_contains($html, 'border-stone-300'))->toBeTrue()
        ->and(str_contains($html, 'value="1"'))->toBeFalse();
});

test('Open Published Sheet offers an Unregistered Participant signup', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 2,
    ]);
    $available = $sheet->options()->create([
        'name' => 'Morning setup',
        'capacity' => 2,
        'position' => 1,
    ]);
    $available->forceFill(['id' => 731003])->save();
    $sheet->options()->create([
        'name' => 'Full cleanup crew',
        'capacity' => 1,
        'claimed_count' => 1,
        'position' => 2,
    ]);

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSeeHtml("optionPublicId: '".$available->public_id."'")
        ->assertSeeHtml('x-on:claim-option.window="$wire.beginClaim($event.detail.optionPublicId)"')
        ->assertSee('Claim')
        ->assertSee('Morning setup')
        ->assertDontSee('Your name')
        ->assertDontSee('Available Options')
        ->assertDontSee('731003');
});

test('Unregistered Participant claims one Option immediately from its Claim button', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 2,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Morning welcome table',
        'capacity' => 2,
        'position' => 1,
    ]);

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->call('beginClaim', $option->public_id)
        ->assertSee('Claim Morning welcome table')
        ->set('name', 'Jordan Lee')
        ->call('claimPending')
        ->assertHasNoErrors()
        ->assertRedirect(route('sheets.show', $sheet));

    $signup = Signup::query()->with('optionClaims')->sole();

    expect($signup->name_snapshot)->toBe('Jordan Lee')
        ->and($signup->optionClaims)->toHaveCount(1)
        ->and($signup->optionClaims->sole()->option_id)->toBe($option->id)
        ->and($option->refresh()->claimed_count)->toBe(1);
});

test('Unregistered Participant Claim opens a name-only modal for the selected Option', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Cooler with drinks',
        'capacity' => 1,
        'position' => 1,
    ]);

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->call('beginClaim', $option->public_id)
        ->assertSet('showNameModal', true)
        ->assertSet('pendingOptionName', 'Cooler with drinks')
        ->assertSee('Claim Cooler with drinks')
        ->assertSee('Your name')
        ->assertSee('Cancel')
        ->assertSeeHtml('data-remember-participant-name')
        ->assertDontSee('Email')
        ->assertDontSee('Phone')
        ->assertDontSee('Visibility Consent')
        ->assertDontSee('Available Options');
});

test('signed-in Participant claims with their Account name', function () {
    $account = Account::factory()->create(['name' => 'Account Name']);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Afternoon welcome table',
        'capacity' => 1,
        'position' => 1,
    ]);

    Livewire::actingAs($account)
        ->test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->assertSet('name', '')
        ->assertSet('usesAccountName', true)
        ->call('beginClaim', $option->public_id)
        ->assertHasNoErrors()
        ->assertSet('showNameModal', false)
        ->assertRedirect(route('sheets.show', $sheet));

    expect(Signup::query()->sole()->name_snapshot)->toBe('Account Name')
        ->and($option->refresh()->claimed_count)->toBe(1);
});

test('Unregistered Participant name is restored and remembered only in the browser', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $sheet->options()->create([
        'name' => 'Remembered-name Option',
        'capacity' => 1,
        'position' => 1,
    ]);

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->call('beginClaim', $sheet->options()->sole()->public_id)
        ->assertSeeHtml('data-remember-participant-name')
        ->assertSeeHtml('localStorage.getItem(\'signup.participant-name\')')
        ->assertSeeHtml('localStorage.setItem(\'signup.participant-name\'');

    $account = Account::factory()->create(['name' => 'Signed-in Name']);

    Livewire::actingAs($account)
        ->test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->assertDontSeeHtml('data-remember-participant-name');
});

test('Unregistered Participant immediately claims another Option without re-entering their name', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 2,
    ]);
    $firstOption = $sheet->options()->create([
        'name' => 'First unregistered Option',
        'capacity' => 1,
        'position' => 1,
    ]);
    $secondOption = $sheet->options()->create([
        'name' => 'Second unregistered Option',
        'capacity' => 1,
        'position' => 2,
    ]);

    $component = Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Remembered Participant')
        ->call('claim', $firstOption->public_id)
        ->assertHasNoErrors()
        ->assertSet('name', 'Remembered Participant');

    $component
        ->call('claim', $secondOption->public_id)
        ->assertHasNoErrors()
        ->assertSet('name', 'Remembered Participant');

    $signup = Signup::query()->with('optionClaims')->sole();

    expect($signup->name_snapshot)->toBe('Remembered Participant')
        ->and($signup->optionClaims)->toHaveCount(2)
        ->and(OptionClaim::query()->pluck('option_id')->sort()->values()->all())
        ->toBe([$firstOption->id, $secondOption->id]);
});

test('immediate Open claims share one browser Signup and enforce its selection maximum', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $firstOption = $sheet->options()->create([
        'name' => 'First limited Option',
        'capacity' => 2,
        'position' => 1,
    ]);
    $secondOption = $sheet->options()->create([
        'name' => 'Second limited Option',
        'capacity' => 2,
        'position' => 2,
    ]);

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->call('beginClaim', $firstOption->public_id)
        ->set('name', 'One Browser Participant')
        ->call('claimPending')
        ->assertHasNoErrors();

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('Yours')
        ->assertSee('Maximum reached')
        ->assertDontSeeHtml('aria-label="Claim Second limited Option"');

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->call('beginClaim', $secondOption->public_id)
        ->set('name', 'One Browser Participant')
        ->call('claimPending')
        ->assertHasErrors(['signup'])
        ->assertSee('Choose between 1 and 1 available Options.');

    expect(Signup::query()->count())->toBe(1)
        ->and(OptionClaim::query()->count())->toBe(1)
        ->and($firstOption->refresh()->claimed_count)->toBe(1)
        ->and($secondOption->refresh()->claimed_count)->toBe(0);
});

test('signed-in immediate Open claims share their Account Signup and enforce its selection maximum', function () {
    $account = Account::factory()->create(['name' => 'Limited Account']);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $firstOption = $sheet->options()->create([
        'name' => 'First Account Option',
        'capacity' => 2,
        'position' => 1,
    ]);
    $secondOption = $sheet->options()->create([
        'name' => 'Second Account Option',
        'capacity' => 2,
        'position' => 2,
    ]);

    Livewire::actingAs($account)
        ->test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->call('beginClaim', $firstOption->public_id)
        ->assertHasNoErrors();

    Livewire::actingAs($account)
        ->test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->call('beginClaim', $secondOption->public_id)
        ->assertHasErrors(['signup'])
        ->assertSee('Choose between 1 and 1 available Options.');

    $signup = Signup::query()->with('optionClaims')->sole();

    expect($signup->account_id)->toBe($account->id)
        ->and($signup->optionClaims)->toHaveCount(1)
        ->and($firstOption->refresh()->claimed_count)->toBe(1)
        ->and($secondOption->refresh()->claimed_count)->toBe(0);
});

test('Published Sheet with no available Options does not offer a Signup action', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $sheet->options()->create([
        'name' => 'Already full',
        'capacity' => 1,
        'claimed_count' => 1,
        'position' => 1,
    ]);

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('Full — unavailable')
        ->assertDontSee('Claim Already full')
        ->assertDontSeeHtml('wire:submit="complete"');
});

test('Unregistered Participant completes a Signup without creating an Account', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'position' => 1,
    ]);
    $accountCount = Account::query()->count();

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', '  Jordan Lee  ')
        ->set('phone', '  555-0102  ')
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSee('Signup complete')
        ->assertSee('cannot be edited or cancelled without an account')
        ->assertDontSee('Edit Signup')
        ->assertDontSee('Cancel Signup');

    $signup = Signup::query()->with('optionClaims.option')->sole();

    expect($signup->name_snapshot)->toBe('Jordan Lee')
        ->and($signup->phone_snapshot)->toBe('555-0102')
        ->and($signup->optionClaims)->toHaveCount(1)
        ->and($signup->optionClaims->sole()->option->public_id)->toBe($option->public_id)
        ->and($option->refresh()->claimed_count)->toBe(1)
        ->and(Account::query()->count())->toBe($accountCount);

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSeeInOrder(['Welcome table', 'Claimed', '1', 'Remaining', '1']);
});

test('future Unregistered Signup persists only consent for participant-visible fields', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
        'name_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'email_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'phone_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Future privacy Option',
        'capacity' => 1,
        'position' => 1,
    ]);

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Future Unregistered Person')
        ->set('phone', '555-0172')
        ->set('nameConsent', true)
        ->set('emailConsent', true)
        ->set('phoneConsent', true)
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSee('Signup complete');

    expect(Signup::query()->sole())
        ->name_consent->toBeTrue()
        ->email_consent->toBeFalse()
        ->phone_consent->toBeTrue();

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('Future Unregistered Person')
        ->assertSee('555-0172');
});

test('existing Unregistered Participant stays private after eligibility broadens', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
        'name_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'email_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'phone_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Existing private Option',
        'capacity' => 1,
        'position' => 1,
    ]);

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->assertDontSee('Visibility Consent')
        ->set('name', 'Existing Unregistered Person')
        ->set('phone', '555-0157')
        ->set('nameConsent', true)
        ->set('emailConsent', true)
        ->set('phoneConsent', true)
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasNoErrors();

    $signup = Signup::query()->with('pendingAccountAssociation')->sole();

    expect($signup)
        ->account_id->toBeNull()
        ->name_consent->toBeFalse()
        ->email_consent->toBeFalse()
        ->phone_consent->toBeFalse()
        ->and($signup->pendingAccountAssociation)->toBeNull();

    $sheet->update([
        'name_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'email_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'phone_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
    ]);

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('EUP')
        ->assertDontSee('Existing Unregistered Person')
        ->assertDontSee('555-0157');

    $this->actingAs($sheet->owner)
        ->get(route('sheets.signups', $sheet))
        ->assertOk()
        ->assertSee('Existing Unregistered Person')
        ->assertSee('555-0157');

    $this->get(route('signups.edit', $signup))
        ->assertNotFound();
});

test('Open Participation accepts every Option up to a configured maximum above 100', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'deadline_at' => now()->addHour(),
        'selection_maximum' => 101,
    ]);

    $optionPublicIds = collect(range(1, 101))
        ->map(fn (int $position): string => $sheet->options()->create([
            'name' => 'Option '.$position,
            'capacity' => 1,
            'position' => $position,
        ])->public_id)
        ->all();

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Maximum Participant')
        ->set('selectedOptions', $optionPublicIds)
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSee('Signup complete');

    expect(Signup::query()->sole()->optionClaims)->toHaveCount(101)
        ->and(OptionClaim::query()->count())->toBe(101)
        ->and($sheet->options()->where('claimed_count', 1)->count())->toBe(101);
});

test('Unregistered Participant may omit a phone number', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Phone optional Option',
        'capacity' => 1,
        'position' => 1,
    ]);

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Casey North')
        ->set('phone', '')
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasNoErrors();

    expect(Signup::query()->sole()->phone_snapshot)->toBeNull();
});

test('Signup validates required identity and selection maximum on the server', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $firstOption = $sheet->options()->create([
        'name' => 'First Option',
        'capacity' => 2,
        'position' => 1,
    ]);
    $secondOption = $sheet->options()->create([
        'name' => 'Second Option',
        'capacity' => 2,
        'position' => 2,
    ]);

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', '   ')
        ->set('selectedOptions', [])
        ->call('complete')
        ->assertHasErrors(['name', 'selectedOptions']);

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Morgan Reed')
        ->set('selectedOptions', [$firstOption->public_id, $secondOption->public_id])
        ->call('complete')
        ->assertHasErrors(['selectedOptions']);

    expect(Signup::query()->count())->toBe(0)
        ->and($firstOption->refresh()->claimed_count)->toBe(0)
        ->and($secondOption->refresh()->claimed_count)->toBe(0);
});

test('capacity race names newly unavailable Options and preserves recoverable input', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 2,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Last serving shift',
        'capacity' => 1,
        'position' => 1,
    ]);
    $stillAvailable = $sheet->options()->create([
        'name' => 'Backup serving shift',
        'capacity' => 1,
        'position' => 2,
    ]);

    $component = Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Avery Stone')
        ->set('phone', '555-0177')
        ->set('selectedOptions', [$option->public_id, $stillAvailable->public_id]);

    $option->update(['claimed_count' => 1]);

    $component
        ->call('complete')
        ->assertHasErrors(['signup'])
        ->assertSee('Newly unavailable: Last serving shift')
        ->assertSet('name', 'Avery Stone')
        ->assertSet('phone', '555-0177')
        ->assertSet('selectedOptions', [$stillAvailable->public_id]);

    expect(Signup::query()->count())->toBe(0)
        ->and($option->refresh()->claimed_count)->toBe(1)
        ->and($stillAvailable->refresh()->claimed_count)->toBe(0);
});

test('server rejects a forged Option from another Signup Sheet', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $sheet->options()->create([
        'name' => 'Owned Option',
        'capacity' => 2,
        'position' => 1,
    ]);
    $otherSheet = Sheet::factory()->create(['selection_maximum' => 1]);
    $foreignOption = $otherSheet->options()->create([
        'name' => 'Foreign Option',
        'capacity' => 2,
        'position' => 1,
    ]);

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Taylor Quinn')
        ->set('selectedOptions', [$foreignOption->public_id])
        ->call('complete')
        ->assertHasErrors(['signup'])
        ->assertSee('do not belong to this Signup Sheet');

    expect(Signup::query()->count())->toBe(0)
        ->and($foreignOption->refresh()->claimed_count)->toBe(0);
});

test('server revalidates Published and open state after the form was rendered', function (array $changes) {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'No longer claimable',
        'capacity' => 1,
        'position' => 1,
    ]);
    $component = Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Riley West')
        ->set('selectedOptions', [$option->public_id]);

    $sheet->update($changes);

    $component
        ->call('complete')
        ->assertOk()
        ->assertHasErrors(['signup'])
        ->assertSee('This Signup Sheet is no longer open for signups.');

    expect(Signup::query()->count())->toBe(0)
        ->and($option->refresh()->claimed_count)->toBe(0);
})->with([
    'no longer Published' => [['state' => Sheet::STATE_DRAFT]],
    'deadline passed' => [['deadline_at' => now()->subSecond()]],
    'no longer Open Participation' => [['participation_policy' => 'verified']],
]);

test('hidden honeypot discards an automated Signup', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Garden gate',
        'capacity' => 2,
        'position' => 1,
    ]);

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Automated Visitor')
        ->set('selectedOptions', [$option->public_id])
        ->set('website', 'https://spam.example')
        ->call('complete')
        ->assertHasNoErrors()
        ->assertDontSee('Signup complete');

    expect(Signup::query()->count())->toBe(0)
        ->and($option->refresh()->claimed_count)->toBe(0);
});

test('Signup attempts are throttled independently per Sheet and IP', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Popular table',
        'capacity' => 10,
        'position' => 1,
    ]);

    foreach (range(1, 5) as $attempt) {
        Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
            ->set('name', 'Participant '.$attempt)
            ->set('selectedOptions', [$option->public_id])
            ->call('complete')
            ->assertHasNoErrors();
    }

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Throttled Participant')
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasErrors(['signup'])
        ->assertSee('Too many signup attempts');

    try {
        $page = $this->get(route('sheets.show', $sheet));
        preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $snapshotMatch);
        $snapshot = html_entity_decode($snapshotMatch[1], ENT_QUOTES | ENT_HTML5);

        $differentIpResponse = $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->withHeaders([
                'X-Livewire' => 'true',
            ])
            ->postJson(route('default-livewire.update', absolute: false), [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [
                        'name' => 'Different IP Participant',
                        'selectedOptions' => [$option->public_id],
                    ],
                    'calls' => [[
                        'method' => 'complete',
                        'params' => [],
                        'path' => '',
                    ]],
                ]],
            ]);

        $differentIpResponse->assertOk();
        expect($differentIpResponse->json('components.0.effects.html'))->toContain('Signup complete');
    } finally {
        $this->withServerVariables([]);
    }

    $otherSheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $otherOption = $otherSheet->options()->create([
        'name' => 'Other Sheet Option',
        'capacity' => 1,
        'position' => 1,
    ]);

    Livewire::test('complete-open-signup', ['sheetPublicId' => $otherSheet->public_id])
        ->set('name', 'Same IP, other Sheet')
        ->set('selectedOptions', [$otherOption->public_id])
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSee('Signup complete');

    expect($option->refresh()->claimed_count)->toBe(6)
        ->and($otherOption->refresh()->claimed_count)->toBe(1);
});

test('Open Participation Signup throttle emits one privacy-safe structured warning without side effects', function () {
    Mail::fake();
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Popular table',
        'capacity' => 10,
        'position' => 1,
    ]);

    foreach (range(1, 5) as $attempt) {
        Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
            ->set('name', 'Participant '.$attempt)
            ->set('selectedOptions', [$option->public_id])
            ->call('complete')
            ->assertHasNoErrors();
    }

    $accountCount = Account::query()->count();
    $signupCount = Signup::query()->count();
    $claimCount = OptionClaim::query()->count();
    Log::spy();

    Livewire::test('complete-open-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Private Participant')
        ->set('email', 'private@example.test')
        ->set('phone', '555-0199')
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasErrors(['signup'])
        ->assertSet('announcement', 'Too many signup attempts. Please wait a minute and try again.')
        ->assertSee('Too many signup attempts');

    Log::shouldHaveReceived('warning')
        ->with('signup.throttled', [
            'operation' => 'submission',
            'participation_policy' => Sheet::PARTICIPATION_OPEN,
            'sheet_public_id' => $sheet->public_id,
        ])
        ->once();
    expect(Account::query()->count())->toBe($accountCount)
        ->and(Signup::query()->count())->toBe($signupCount)
        ->and(OptionClaim::query()->count())->toBe($claimCount)
        ->and($option->refresh()->claimed_count)->toBe(5);
    Mail::assertNothingQueued();
});

test('Livewire Signup endpoint requires CSRF protection', function () {
    $this->app['env'] = 'local';

    $this->withMiddleware(PreventRequestForgery::class)
        ->withHeader('X-Livewire', 'true')
        ->postJson(route('default-livewire.update', absolute: false), [])
        ->assertStatus(419);
});
