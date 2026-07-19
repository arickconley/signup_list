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

test('Draft captures optional content and safe defaults in the Owner timezone', function () {
    $this->travelTo(Carbon::parse('2026-03-01 12:00:00 UTC'));

    $account = Account::factory()->create(['timezone' => 'America/Los_Angeles']);
    $this->actingAs($account);

    Livewire::test('pages::sheets.create')
        ->set('title', 'Spring fundraiser')
        ->set('description', 'Bring baked goods to share.')
        ->set('eventAt', '2026-03-10T18:30')
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
        ->and($sheet->event_at?->toIso8601String())->toBe('2026-03-11T01:30:00+00:00');

    $this->get(route('sheets.edit', $sheet))
        ->assertOk()
        ->assertSee('Bring baked goods to share.')
        ->assertSee('Community Hall')
        ->assertSee('Mar 10, 2026 6:30 PM PDT')
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
