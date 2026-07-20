<?php

use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('verified eligible Account can open Draft Sheet creation page', function () {
    $account = Account::factory()->create();

    $this->actingAs($account)
        ->get(route('sheets.create'))
        ->assertOk()
        ->assertSee('Create a signup sheet');
});

test('Owner creates Options and publishes directly from the initial Sheet form', function () {
    $this->travelTo(Carbon::parse('2026-08-01 12:00:00 UTC'));

    $account = Account::factory()->create(['timezone' => 'America/Los_Angeles']);
    $this->actingAs($account);

    $component = Livewire::test('pages::sheets.create')
        ->assertSee('Options')
        ->assertSee('Add another Option')
        ->assertSee('Publish Sheet')
        ->set('title', 'Neighborhood picnic')
        ->set('deadlineAt', '2026-08-08T18:00')
        ->set('optionRows.0.name', 'Bring drinks')
        ->set('optionRows.0.description', 'Water and lemonade')
        ->set('optionRows.0.capacity', '3')
        ->set('selectionMaximum', '1')
        ->call('publish')
        ->assertHasNoErrors();

    $sheet = $account->ownedSheets()->sole();
    $option = $sheet->options()->sole();

    $component->assertRedirect(route('sheets.edit', $sheet, absolute: false));

    expect($sheet)
        ->state->toBe(Sheet::STATE_PUBLISHED)
        ->selection_maximum->toBe(1)
        ->and($option)
        ->name->toBe('Bring drinks')
        ->description->toBe('Water and lemonade')
        ->capacity->toBe(3)
        ->position->toBe(1);

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('Neighborhood picnic')
        ->assertSee('Bring drinks')
        ->assertSee('Water and lemonade');
});

test('publishing from the initial form requires at least one valid Option', function () {
    $account = Account::factory()->create();
    $this->actingAs($account);

    Livewire::test('pages::sheets.create')
        ->set('title', 'Incomplete picnic')
        ->call('publish')
        ->assertHasErrors(['options'])
        ->assertSee('Add at least one valid Option before publishing.');

    expect($account->ownedSheets()->exists())->toBeFalse();
});

test('Owner saves multiple initial Options in a Draft Sheet', function () {
    $account = Account::factory()->create();
    $this->actingAs($account);

    Livewire::test('pages::sheets.create')
        ->set('title', 'Volunteer shifts')
        ->set('optionRows.0.name', 'Morning setup')
        ->set('optionRows.0.capacity', '2')
        ->call('addOptionRow')
        ->assertCount('optionRows', 2)
        ->set('optionRows.1.name', 'Evening cleanup')
        ->set('optionRows.1.capacity', '4')
        ->set('selectionMaximum', '2')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $sheet = $account->ownedSheets()->sole();

    expect($sheet)
        ->state->toBe(Sheet::STATE_DRAFT)
        ->selection_maximum->toBe(2)
        ->and($sheet->options()->orderBy('position')->pluck('name')->all())
        ->toBe(['Morning setup', 'Evening cleanup'])
        ->and($sheet->options()->orderBy('position')->pluck('capacity')->all())
        ->toBe([2, 4]);
});

test('unverified Account cannot create a Draft Sheet', function () {
    $account = Account::factory()->unverified()->create();
    $this->actingAs($account);

    $this->get(route('sheets.create'))
        ->assertRedirect(route('verification.notice'));

    Livewire::test('pages::sheets.create')
        ->set('title', 'Unverified ownership attempt')
        ->call('save')
        ->assertHasErrors(['ownership']);

    expect($account->ownedSheets()->exists())->toBeFalse();
});

test('Draft Sheet requires a title', function () {
    $account = Account::factory()->create();
    $this->actingAs($account);

    Livewire::test('pages::sheets.create')
        ->set('title', '   ')
        ->call('save')
        ->assertHasErrors(['title']);

    expect($account->ownedSheets()->exists())->toBeFalse();
});

test('Owner sets the Signup deadline in their profile timezone', function () {
    $this->travelTo(Carbon::parse('2026-03-01 12:00:00 UTC'));

    $account = Account::factory()->create(['timezone' => 'America/Los_Angeles']);
    $this->actingAs($account);

    Livewire::test('pages::sheets.create')
        ->assertSet('deadlineAt', '2026-03-15T23:59')
        ->assertSee('Signup deadline')
        ->set('title', 'Spring fundraiser')
        ->set('eventAt', '2026-03-10T18:30')
        ->set('deadlineAt', '2026-03-09T20:00')
        ->call('save')
        ->assertHasNoErrors();

    $sheet = $account->ownedSheets()->sole();

    expect($sheet->deadline_at->toIso8601String())->toBe('2026-03-10T03:00:00+00:00')
        ->and($sheet->event_at?->toIso8601String())->toBe('2026-03-11T01:30:00+00:00');
});

test('Signup deadline cannot be after the event date and time', function () {
    $account = Account::factory()->create(['timezone' => 'America/Los_Angeles']);
    $this->actingAs($account);

    Livewire::test('pages::sheets.create')
        ->set('title', 'Spring fundraiser')
        ->set('eventAt', '2026-08-10T18:30')
        ->set('deadlineAt', '2026-08-10T18:31')
        ->call('save')
        ->assertHasErrors(['deadlineAt'])
        ->assertSee('Signup deadline must be at or before the event date and time.');

    expect($account->ownedSheets()->exists())->toBeFalse();
});

test('Signup deadline must be in the future', function () {
    $this->travelTo(Carbon::parse('2026-08-01 12:00:00 UTC'));

    $account = Account::factory()->create(['timezone' => 'America/Los_Angeles']);
    $this->actingAs($account);

    Livewire::test('pages::sheets.create')
        ->set('title', 'Expired fundraiser')
        ->set('deadlineAt', '2026-07-31T20:00')
        ->call('save')
        ->assertHasErrors(['deadlineAt'])
        ->assertSee('Signup deadline must be in the future.');

    expect($account->ownedSheets()->exists())->toBeFalse();
});

test('Draft captures optional content and safe defaults in the Owner timezone', function () {
    $this->travelTo(Carbon::parse('2026-03-01 12:00:00 UTC'));

    $account = Account::factory()->create(['timezone' => 'America/Los_Angeles']);
    $this->actingAs($account);

    Livewire::test('pages::sheets.create')
        ->set('title', 'Spring fundraiser')
        ->set('description', 'Bring baked goods to share.')
        ->set('eventAt', '2026-03-20T18:30')
        ->set('location', 'Community Hall')
        ->call('save')
        ->assertHasNoErrors();

    $sheet = $account->ownedSheets()->sole();

    expect($sheet)
        ->state->toBe(Sheet::STATE_DRAFT)
        ->participation_policy->toBe(Sheet::PARTICIPATION_OPEN)
        ->name_visibility->toBe(Sheet::VISIBILITY_OWNER_ONLY)
        ->email_visibility->toBe(Sheet::VISIBILITY_OWNER_ONLY)
        ->phone_visibility->toBe(Sheet::VISIBILITY_OWNER_ONLY)
        ->timezone->toBe('America/Los_Angeles')
        ->and($sheet->deadline_at->toIso8601String())->toBe('2026-03-16T06:59:00+00:00')
        ->and($sheet->event_at?->toIso8601String())->toBe('2026-03-21T01:30:00+00:00');

    $this->get(route('sheets.edit', $sheet))
        ->assertOk()
        ->assertSee('Bring baked goods to share.')
        ->assertSee('Community Hall')
        ->assertSee('Mar 20, 2026 6:30 PM PDT')
        ->assertSee('Mar 15, 2026 11:59 PM PDT')
        ->assertSee('Open Participation')
        ->assertSee('Owner only');
});

test('Owner can choose Verified Participation when creating a Draft Sheet', function () {
    $account = Account::factory()->create();
    $this->actingAs($account);

    Livewire::test('pages::sheets.create')
        ->assertSet('participationPolicy', Sheet::PARTICIPATION_OPEN)
        ->assertSee('Open Participation')
        ->assertSee('Verified Participation')
        ->set('title', 'Verified volunteers')
        ->set('participationPolicy', Sheet::PARTICIPATION_VERIFIED)
        ->call('save')
        ->assertHasNoErrors();

    expect($account->ownedSheets()->sole()->participation_policy)
        ->toBe(Sheet::PARTICIPATION_VERIFIED);
});

test('Participation Policy validation error is associated with the create radio group', function () {
    $account = Account::factory()->create();
    $this->actingAs($account);

    Livewire::test('pages::sheets.create')
        ->set('title', 'Invalid policy draft')
        ->set('participationPolicy', 'invalid')
        ->call('save')
        ->assertHasErrors(['participationPolicy'])
        ->assertSeeHtml('aria-invalid="true" aria-describedby="participation-policy-error"')
        ->assertSeeHtml('id="participation-policy-error"');
});

test('eligible Account creates a UUID-addressed Draft visible on its dashboard', function () {
    $account = Account::factory()->create();
    $this->actingAs($account);

    Livewire::test('pages::sheets.create')
        ->set('title', 'Neighborhood cleanup')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $neighborhoodCleanup = $account->ownedSheets()
        ->where('title', 'Neighborhood cleanup')
        ->sole();

    Livewire::test('pages::sheets.create')
        ->set('title', 'Community potluck')
        ->call('save')
        ->assertHasNoErrors();

    $publicIds = $account->ownedSheets()->pluck('public_id');

    expect($publicIds)->toHaveCount(2)
        ->and($publicIds->unique())->toHaveCount(2)
        ->and($publicIds->every(fn (string $publicId): bool => Str::isUuid($publicId, 4)))->toBeTrue();

    $dashboard = $this->get(route('dashboard'));

    $dashboard
        ->assertOk()
        ->assertSee('shareable link')
        ->assertDontSee('private link')
        ->assertSee('Neighborhood cleanup')
        ->assertSee('Draft')
        ->assertSee(route('sheets.edit', $neighborhoodCleanup, absolute: false), escape: false);

    $this->get(route('sheets.edit', $neighborhoodCleanup))
        ->assertOk()
        ->assertSee('Neighborhood cleanup');
});

test('disposable domains and their subdomains cannot become Owners', function (string $email) {
    $account = Account::factory()->create(['email' => $email]);
    $this->actingAs($account);

    Livewire::test('pages::sheets.create')
        ->set('title', 'Blocked ownership attempt')
        ->call('save')
        ->assertHasErrors(['ownership'])
        ->assertSee('This email domain cannot be used to create signup sheets.');

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Blocked ownership attempt');
})->with([
    'normalized base domain' => 'Owner@MAILINATOR.COM',
    'normalized subdomain' => 'owner@News.Mailinator.com',
]);

test('Draft Sheet is visible only to its Owner', function () {
    $owner = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Owner private draft',
    ]);

    $this->actingAs($otherAccount)
        ->get(route('sheets.edit', $sheet))
        ->assertNotFound();

    $this->actingAs($owner)
        ->get(route('sheets.edit', $sheet))
        ->assertOk()
        ->assertSee('Owner private draft');
});
