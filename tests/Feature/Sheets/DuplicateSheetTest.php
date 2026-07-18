<?php

use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('eligible Owner duplicates an owned Signup Sheet into a new Draft edit page', function () {
    $owner = Account::factory()->create();
    $source = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Published neighborhood cleanup',
        'state' => Sheet::STATE_PUBLISHED,
    ]);
    $this->actingAs($owner);

    $component = Livewire::test('pages::sheets.edit', ['sheet' => $source])
        ->assertSee('Duplicate Sheet')
        ->assertSee('Start a new Draft Sheet')
        ->call('duplicate');

    $duplicate = $owner->ownedSheets()->whereKeyNot($source->id)->sole();

    $component->assertRedirect(route('sheets.edit', $duplicate, absolute: false));
    expect(session('success'))->toBe('Signup Sheet duplicated into a new Draft Sheet.');

    expect($duplicate)
        ->title->toBe('Published neighborhood cleanup')
        ->state->toBe(Sheet::STATE_DRAFT);

    $this->actingAs(Account::factory()->create())
        ->get(route('sheets.edit', $duplicate))
        ->assertNotFound();
});

test('duplicate copies reusable content and settings from any Sheet state', function (string $sourceState) {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00 UTC'));

    $owner = Account::factory()->create(['timezone' => 'America/New_York']);
    $source = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Community fundraiser',
        'description' => 'Choose how you can help.',
        'event_at' => Carbon::parse('2026-12-05 01:30:00 UTC'),
        'location' => 'Town Hall',
        'deadline_at' => Carbon::parse('2027-01-01 00:00:00 UTC'),
        'timezone' => 'Europe/Paris',
        'state' => $sourceState,
        'participation_policy' => 'verified',
        'selection_maximum' => 2,
        'name_visibility' => 'participants',
        'email_visibility' => 'participants',
        'phone_visibility' => 'participants',
    ]);
    $source->options()->create([
        'name' => 'Cleanup',
        'description' => 'Stay afterward.',
        'capacity' => 4,
        'claimed_count' => 3,
        'position' => 20,
    ]);
    $source->options()->create([
        'name' => 'Setup',
        'description' => null,
        'capacity' => 2,
        'position' => 10,
    ]);
    $source->options()->create([
        'name' => 'Welcome table',
        'description' => 'Greet each participant.',
        'capacity' => 7,
        'position' => 30,
    ]);
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $source])
        ->call('duplicate');

    $duplicate = $owner->ownedSheets()->whereKeyNot($source->id)->sole();
    $options = $duplicate->options()->orderBy('position')->get();

    expect($duplicate)
        ->title->toBe('Community fundraiser')
        ->description->toBe('Choose how you can help.')
        ->location->toBe('Town Hall')
        ->participation_policy->toBe('verified')
        ->selection_maximum->toBe(2)
        ->name_visibility->toBe('participants')
        ->email_visibility->toBe('participants')
        ->phone_visibility->toBe('participants')
        ->timezone->toBe('America/New_York')
        ->state->toBe(Sheet::STATE_DRAFT)
        ->and($duplicate->event_at?->toIso8601String())->toBe('2026-12-05T01:30:00+00:00')
        ->and($duplicate->deadline_at->toIso8601String())->toBe('2026-11-09T04:59:00+00:00')
        ->and($duplicate->public_id)->not->toBe($source->public_id)
        ->and(Str::isUuid($duplicate->public_id, 4))->toBeTrue()
        ->and($options->pluck('name')->all())->toBe(['Setup', 'Cleanup', 'Welcome table'])
        ->and($options->pluck('description')->all())->toBe([null, 'Stay afterward.', 'Greet each participant.'])
        ->and($options->pluck('capacity')->all())->toBe([2, 4, 7])
        ->and($options->pluck('claimed_count')->all())->toBe([0, 0, 0])
        ->and($options->pluck('position')->all())->toBe([10, 20, 30]);
})->with([
    'Draft source' => Sheet::STATE_DRAFT,
    'Published source' => Sheet::STATE_PUBLISHED,
]);

test('other Account cannot duplicate an Owners Sheet through a stale management action', function () {
    $owner = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $source = Sheet::factory()->for($owner, 'owner')->create();

    $component = Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $source]);
    $this->actingAs($otherAccount);

    $component->call('duplicate')->assertNotFound();

    expect(Sheet::query()->count())->toBe(1);
});

test('ineligible existing Owner cannot duplicate a Sheet', function (?string $verifiedAt, string $email) {
    $owner = Account::factory()->create([
        'email' => $email,
        'email_verified_at' => $verifiedAt,
    ]);
    $source = Sheet::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $source])
        ->call('duplicate')
        ->assertForbidden();

    expect($owner->ownedSheets()->count())->toBe(1);
})->with([
    'unverified Account' => [null, 'owner@example.com'],
    'disposable email domain' => ['2026-07-18 12:00:00', 'owner@mailinator.com'],
]);

test('duplicate assigns fresh Sheet and Option identities', function () {
    $owner = Account::factory()->create();
    $source = Sheet::factory()->for($owner, 'owner')->create();
    $source->options()->create([
        'name' => 'Setup',
        'capacity' => 2,
        'position' => 1,
    ]);
    $source->options()->create([
        'name' => 'Cleanup',
        'capacity' => 3,
        'position' => 2,
    ]);
    $sourceOptionIds = $source->options()->pluck('id')->all();
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $source])
        ->call('duplicate');

    $duplicate = $owner->ownedSheets()->whereKeyNot($source->id)->sole();
    $duplicateOptions = $duplicate->options()->orderBy('position')->get();

    expect($duplicate->id)->not->toBe($source->id)
        ->and($duplicate->public_id)->not->toBe($source->public_id)
        ->and($duplicateOptions->pluck('id')->intersect($sourceOptionIds))->toBeEmpty()
        ->and($duplicateOptions->pluck('sheet_id')->unique()->all())->toBe([$duplicate->id])
        ->and($source->options()->pluck('sheet_id')->unique()->all())->toBe([$source->id]);
});
