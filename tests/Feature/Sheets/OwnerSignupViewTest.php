<?php

use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

test('only the Owner can open the Signup View and it cannot be indexed', function () {
    $owner = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Private volunteer roster',
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);

    $url = route('sheets.signups', $sheet, absolute: false);

    expect($url)->toBe('/sheets/'.$sheet->public_id.'/signups');

    $this->get($url)
        ->assertRedirect(route('login'));

    $this->actingAs($otherAccount)
        ->get($url)
        ->assertNotFound()
        ->assertDontSee('Private volunteer roster');

    $this->actingAs($owner)
        ->get($url)
        ->assertOk()
        ->assertSee('Private volunteer roster')
        ->assertSee('Signup View')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSeeHtml('<meta name="robots" content="noindex, nofollow">');
});

test('Owner switches between Participant and Option grouping without losing Sheet context', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Neighborhood meal train',
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 2,
    ]);
    $main = $sheet->options()->create([
        'name' => 'Main dish',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $dessert = $sheet->options()->create([
        'name' => 'Dessert',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 2,
    ]);
    $alex = $sheet->signups()->create([
        'name_snapshot' => 'Alex Rivera',
        'email_snapshot' => 'alex@example.test',
    ]);
    $alex->optionClaims()->create(['option_id' => $main->id]);
    $sam = $sheet->signups()->create([
        'name_snapshot' => 'Sam Lee',
        'phone_snapshot' => '555-0111',
    ]);
    $sam->optionClaims()->create(['option_id' => $dessert->id]);

    Livewire::actingAs($owner)
        ->test('pages::sheets.signups', ['sheet' => $sheet])
        ->assertSet('grouping', 'participant')
        ->assertSee('Grouped by Participant')
        ->call('showOptionGrouping')
        ->assertSet('grouping', 'option')
        ->assertSee('Grouped by Option')
        ->assertSeeInOrder(['Main dish', 'Alex Rivera', 'Dessert', 'Sam Lee'])
        ->assertSee('Neighborhood meal train')
        ->assertSee('alex@example.test')
        ->assertSee('555-0111')
        ->call('showParticipantGrouping')
        ->assertSet('grouping', 'participant')
        ->assertSee('Grouped by Participant')
        ->assertSee('Neighborhood meal train');
});

test('Option grouping uses a supported breakpoint for participant contact details', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Supported breakpoint Option',
        'capacity' => 1,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Responsive Participant',
        'email_snapshot' => 'responsive@example.test',
    ]);
    $signup->optionClaims()->create(['option_id' => $option->id]);

    Livewire::actingAs($owner)
        ->test('pages::sheets.signups', ['sheet' => $sheet])
        ->call('showOptionGrouping')
        ->assertSeeHtml('grid gap-3 text-sm sm:grid-cols-2')
        ->assertDontSeeHtml('xs:grid-cols-2');
});

test('Participant grouping shows immutable snapshots, Option Claims, association state, and over-limit Signups', function () {
    $owner = Account::factory()->create();
    $attachedAccount = Account::factory()->create([
        'name' => 'Current Profile Name',
        'email' => 'current-profile@example.test',
        'phone' => '555-9999',
    ]);
    $pendingAccount = Account::factory()->unverified()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
        'name_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'email_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'phone_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
    ]);
    $welcome = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 3,
        'claimed_count' => 3,
        'position' => 1,
    ]);
    $cleanup = $sheet->options()->create([
        'name' => 'Evening cleanup',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 2,
    ]);

    $attachedSignup = $sheet->signups()->create([
        'name_snapshot' => 'Submitted Name',
        'email_snapshot' => 'submitted@example.test',
        'phone_snapshot' => '555-0102',
        'name_consent' => false,
        'email_consent' => false,
        'phone_consent' => false,
    ]);
    $attachedSignup->forceFill(['account_id' => $attachedAccount->id])->save();
    $attachedSignup->optionClaims()->createMany([
        ['option_id' => $welcome->id],
        ['option_id' => $cleanup->id],
    ]);

    $pendingSignup = $sheet->signups()->create([
        'name_snapshot' => 'Pending Person',
        'email_snapshot' => 'pending@example.test',
    ]);
    $pendingSignup->pendingAccountAssociation()->create(['account_id' => $pendingAccount->id]);
    $pendingSignup->optionClaims()->create(['option_id' => $welcome->id]);

    $unregisteredSignup = $sheet->signups()->create([
        'name_snapshot' => 'Unregistered Person',
        'phone_snapshot' => '555-0103',
    ]);
    $unregisteredSignup->optionClaims()->create(['option_id' => $welcome->id]);

    Model::preventLazyLoading();

    try {
        $response = $this->actingAs($owner)->get(route('sheets.signups', $sheet));
    } finally {
        Model::preventLazyLoading(false);
    }

    $response
        ->assertOk()
        ->assertSee('Grouped by Participant')
        ->assertSeeInOrder(['Submitted Name', 'Welcome table', 'Evening cleanup'])
        ->assertSee('submitted@example.test')
        ->assertSee('555-0102')
        ->assertSee('Attached Account')
        ->assertSee('Pending Person')
        ->assertSee('Pending Account Association')
        ->assertSee('Unregistered Person')
        ->assertSee('Unregistered Participant')
        ->assertSee('Over limit — 2 of 1 maximum')
        ->assertDontSee('Current Profile Name')
        ->assertDontSee('current-profile@example.test')
        ->assertDontSee('555-9999');

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertDontSee('Submitted Name')
        ->assertDontSee('submitted@example.test')
        ->assertDontSee('555-0102');
});

test('both groupings show current totals and full, Over-Capacity, and over-limit states', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $full = $sheet->options()->create([
        'name' => 'Full breakfast',
        'capacity' => 2,
        'claimed_count' => 2,
        'position' => 1,
    ]);
    $overCapacity = $sheet->options()->create([
        'name' => 'Over-Capacity chairs',
        'capacity' => 1,
        'claimed_count' => 2,
        'position' => 2,
    ]);
    $available = $sheet->options()->create([
        'name' => 'Available drinks',
        'capacity' => 3,
        'claimed_count' => 1,
        'position' => 3,
    ]);

    $overLimitSignup = $sheet->signups()->create(['name_snapshot' => 'Over Limit Person']);
    $overLimitSignup->optionClaims()->createMany([
        ['option_id' => $full->id],
        ['option_id' => $overCapacity->id],
    ]);
    $secondSignup = $sheet->signups()->create(['name_snapshot' => 'Second Person']);
    $secondSignup->optionClaims()->createMany([
        ['option_id' => $full->id],
        ['option_id' => $overCapacity->id],
    ]);
    $thirdSignup = $sheet->signups()->create(['name_snapshot' => 'Third Person']);
    $thirdSignup->optionClaims()->create(['option_id' => $available->id]);

    $component = Livewire::actingAs($owner)
        ->test('pages::sheets.signups', ['sheet' => $sheet])
        ->assertSeeInOrder(['Total capacity', '6', 'Claimed', '5', 'Remaining', '2'])
        ->assertSeeInOrder(['Full breakfast', 'Full'])
        ->assertSeeInOrder(['Over-Capacity chairs', 'Over-Capacity — 1 over'])
        ->assertSeeInOrder(['Available drinks', '2 remaining'])
        ->assertSee('Over limit — 2 of 1 maximum');

    $component
        ->call('showOptionGrouping')
        ->assertSeeInOrder(['Total capacity', '6', 'Claimed', '5', 'Remaining', '2'])
        ->assertSee('Full breakfast')
        ->assertSee('Full')
        ->assertSee('Over-Capacity chairs')
        ->assertSee('Over-Capacity — 1 over')
        ->assertSee('Available drinks')
        ->assertSee('2 remaining')
        ->assertSee('Over limit — 2 of 1 maximum');
});

test('published Sheet editing links the Owner to its Signup View', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 1,
        'position' => 1,
    ]);

    $this->actingAs($owner)
        ->get(route('sheets.edit', $sheet))
        ->assertOk()
        ->assertSee('View Signups')
        ->assertSeeHtml('href="'.route('sheets.signups', $sheet, absolute: false).'"');
});

test('empty Signup View has keyboard grouping controls and loading and reactive status markup', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Future volunteer day',
        'selection_maximum' => null,
    ]);

    $response = $this->actingAs($owner)->get(route('sheets.signups', $sheet));

    $response
        ->assertOk()
        ->assertSee('No Signups yet')
        ->assertSeeHtml('<fieldset>')
        ->assertSeeHtml('<legend')
        ->assertSeeHtml('type="button"')
        ->assertSeeHtml('aria-pressed="true"')
        ->assertSeeHtml('aria-controls="signup-grouping-results"')
        ->assertSeeHtml('wire:loading.delay')
        ->assertSeeHtml('role="status"')
        ->assertSeeHtml('aria-live="polite"')
        ->assertSeeHtml('focus-visible:')
        ->assertSeeHtml('min-h-11')
        ->assertSeeHtml('sm:grid-cols-');

    expect(substr_count($response->getContent(), '<h1'))->toBe(1);

    $this->get(route('sheets.signups', $sheet).'?group=option')
        ->assertOk()
        ->assertSee('Grouped by Option')
        ->assertSee('No Options yet')
        ->assertSee('Future volunteer day');
});

test('another Account cannot rehydrate an Owner Signup View component', function () {
    $owner = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();

    $component = Livewire::actingAs($owner)
        ->test('pages::sheets.signups', ['sheet' => $sheet]);

    $this->actingAs($otherAccount);

    $component->call('showOptionGrouping')->assertNotFound();
});
