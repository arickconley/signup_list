<?php

use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('Owner adds an Option to a Draft Sheet', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->assertSee('Participant visibility')
        ->assertSee('Contact visibility')
        ->assertDontSee('Participant details')
        ->set('optionName', 'Morning setup')
        ->set('optionDescription', 'Arrive fifteen minutes early.')
        ->set('optionCapacity', '3')
        ->call('addOption')
        ->assertHasNoErrors()
        ->assertSee('Morning setup')
        ->assertSee('Arrive fifteen minutes early.')
        ->assertSee('Capacity: 3');

    $this->get(route('sheets.edit', $sheet))
        ->assertOk()
        ->assertSee('Morning setup')
        ->assertSee('Capacity: 3');
});

test('Option requires a name and positive whole-number capacity', function (string $name, string $capacity, string $errorKey) {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->set('optionName', $name)
        ->set('optionCapacity', $capacity)
        ->call('addOption')
        ->assertHasErrors([$errorKey]);
})->with([
    'missing name' => ['   ', '1', 'optionName'],
    'zero capacity' => ['Zero capacity', '0', 'optionCapacity'],
    'negative capacity' => ['Negative capacity', '-1', 'optionCapacity'],
    'decimal capacity' => ['Decimal capacity', '1.5', 'optionCapacity'],
]);

test('database rejects invalid Option capacity', function (int|float $capacity) {
    $sheet = Sheet::factory()->create();

    expect(fn () => DB::table('options')->insert([
        'sheet_id' => $sheet->id,
        'name' => 'Invalid capacity',
        'capacity' => $capacity,
        'position' => 1,
    ]))->toThrow(QueryException::class);
})->with([
    'zero' => 0,
    'negative' => -1,
    'fractional' => 1.5,
]);

test('Owner edits an Option on a Draft Sheet', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $option = $sheet->options()->create([
        'name' => 'Afternoon setup',
        'description' => 'Old instructions.',
        'capacity' => 2,
        'position' => 1,
    ]);
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->call('startEditingOption', $option->id)
        ->set('editOptionName', 'Evening cleanup')
        ->set('editOptionDescription', '')
        ->set('editOptionCapacity', '5')
        ->call('updateOption')
        ->assertHasNoErrors()
        ->assertSee('Evening cleanup')
        ->assertSee('Capacity: 5')
        ->assertDontSee('Old instructions.');

    $this->get(route('sheets.edit', $sheet))
        ->assertOk()
        ->assertSee('Evening cleanup')
        ->assertDontSee('Old instructions.');
});

test('Owner removes an Option from a Draft Sheet', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $option = $sheet->options()->create([
        'name' => 'No longer needed',
        'capacity' => 1,
        'position' => 1,
    ]);
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->assertSeeHtml('wire:confirm')
        ->call('removeOption', $option->id)
        ->assertHasNoErrors()
        ->assertDontSee('No longer needed');

    $this->get(route('sheets.edit', $sheet))
        ->assertOk()
        ->assertDontSee('No longer needed');
});

test('Owner reorders Options on a Draft Sheet', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $first = $sheet->options()->create(['name' => 'First shift', 'capacity' => 1, 'position' => 1]);
    $sheet->options()->create(['name' => 'Second shift', 'capacity' => 1, 'position' => 2]);
    $third = $sheet->options()->create(['name' => 'Third shift', 'capacity' => 1, 'position' => 3]);
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->call('moveOptionUp', $third->id)
        ->assertHasNoErrors()
        ->assertSeeInOrder(['First shift', 'Third shift', 'Second shift'])
        ->call('moveOptionDown', $first->id)
        ->assertSeeInOrder(['Third shift', 'First shift', 'Second shift']);

    $this->get(route('sheets.edit', $sheet))
        ->assertOk()
        ->assertSeeInOrder(['Third shift', 'First shift', 'Second shift']);
});

test('Owner updates Draft Sheet details and selection maximum', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $sheet->options()->create(['name' => 'Setup', 'capacity' => 2, 'position' => 1]);
    $sheet->options()->create(['name' => 'Cleanup', 'capacity' => 2, 'position' => 2]);
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->set('title', 'Updated community cleanup')
        ->set('description', 'Choose how you can help.')
        ->set('eventAt', '2026-08-10T18:00')
        ->set('location', 'River Park')
        ->set('deadlineAt', '2026-08-09T23:59')
        ->set('selectionMaximum', '2')
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->assertSee('Draft details saved.');

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet->refresh()])
        ->assertSet('title', 'Updated community cleanup')
        ->assertSet('description', 'Choose how you can help.')
        ->assertSet('eventAt', '2026-08-10T18:00')
        ->assertSet('location', 'River Park')
        ->assertSet('deadlineAt', '2026-08-09T23:59')
        ->assertSet('selectionMaximum', '2')
        ->assertSee('Preview')
        ->assertSee('Updated community cleanup')
        ->assertSee('River Park');
});

test('selection maximum must be positive and no greater than the Option count', function (string $selectionMaximum) {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $sheet->options()->create(['name' => 'Setup', 'capacity' => 1, 'position' => 1]);
    $sheet->options()->create(['name' => 'Cleanup', 'capacity' => 1, 'position' => 2]);
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->set('selectionMaximum', $selectionMaximum)
        ->call('saveDetails')
        ->assertHasErrors(['selectionMaximum']);
})->with([
    'zero' => '0',
    'negative' => '-1',
    'decimal' => '1.5',
    'greater than Option count' => '3',
]);

test('removing an Option clamps selection maximum and keeps positions contiguous', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create(['selection_maximum' => 3]);
    $sheet->options()->create(['name' => 'First shift', 'capacity' => 1, 'position' => 1]);
    $second = $sheet->options()->create(['name' => 'Second shift', 'capacity' => 1, 'position' => 2]);
    $sheet->options()->create(['name' => 'Third shift', 'capacity' => 1, 'position' => 3]);
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->call('removeOption', $second->id)
        ->assertHasNoErrors()
        ->assertSet('selectionMaximum', '2')
        ->assertSeeInOrder(['First shift', 'Third shift']);

    $remainingOptions = $sheet->options()->orderBy('position')->get();

    expect($remainingOptions->pluck('name')->all())->toBe(['First shift', 'Third shift'])
        ->and($remainingOptions->pluck('position')->all())->toBe([1, 2]);
});

test('Owner can change a Signup Sheet participation policy', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->assertSet('participationPolicy', Sheet::PARTICIPATION_OPEN)
        ->assertSee('Open Participation')
        ->assertSee('Verified Participation')
        ->set('participationPolicy', Sheet::PARTICIPATION_VERIFIED)
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->assertSee('Verified Participation');

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet->refresh()])
        ->assertSet('participationPolicy', Sheet::PARTICIPATION_VERIFIED)
        ->assertSee('Verified Participation');
});

test('Owner publishes a valid Draft Sheet to its UUID link', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create(['selection_maximum' => 1]);
    $sheet->options()->create(['name' => 'Welcome table', 'capacity' => 2, 'position' => 1]);
    $this->actingAs($owner);

    $shareUrl = url('/sheets/'.$sheet->public_id);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->call('publish')
        ->assertHasNoErrors()
        ->assertSee('Published')
        ->assertSee('Shareable link')
        ->assertSeeHtml('href="'.$shareUrl.'"')
        ->assertDontSeeHtml('href="'.url('/sheets/'.$sheet->id).'"');

    expect($sheet->refresh()->state)->toBe(Sheet::STATE_PUBLISHED);
});

test('publishing requires complete Sheet fields and at least one Option', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'title' => '',
        'selection_maximum' => null,
    ]);
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->set('deadlineAt', '')
        ->call('publish')
        ->assertHasErrors(['title', 'deadlineAt', 'selectionMaximum', 'options'])
        ->assertSeeHtml('id="publishing-options-error"')
        ->assertSee('Add at least one valid Option before publishing.');

    expect($sheet->refresh()->state)->toBe(Sheet::STATE_DRAFT);
});

test('publishing atomically saves the current Sheet details', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create(['selection_maximum' => 1]);
    $sheet->options()->create(['name' => 'Food table', 'capacity' => 3, 'position' => 1]);
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->set('title', 'Published fundraiser')
        ->set('description', 'Choose one way to contribute.')
        ->set('eventAt', '2026-09-05T17:30')
        ->set('location', 'Town Hall')
        ->set('deadlineAt', '2026-09-04T23:59')
        ->set('selectionMaximum', '1')
        ->call('publish')
        ->assertHasNoErrors()
        ->assertSee('Published fundraiser');

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet->refresh()])
        ->assertSet('title', 'Published fundraiser')
        ->assertSet('description', 'Choose one way to contribute.')
        ->assertSet('eventAt', '2026-09-05T17:30')
        ->assertSet('location', 'Town Hall')
        ->assertSet('deadlineAt', '2026-09-04T23:59')
        ->assertSet('selectionMaximum', '1')
        ->assertSee('Published');

    expect($sheet->state)->toBe(Sheet::STATE_PUBLISHED);
});

test('publishing rejects an invalid existing Option', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create(['selection_maximum' => 1]);
    $sheet->options()->create(['name' => '   ', 'capacity' => 1, 'position' => 1]);
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->call('publish')
        ->assertHasErrors(['options']);

    expect($sheet->refresh()->state)->toBe(Sheet::STATE_DRAFT);
});

test('other Account cannot call Draft mutations through Livewire', function () {
    $owner = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create(['selection_maximum' => 1]);
    $option = $sheet->options()->create(['name' => 'Owner Option', 'capacity' => 1, 'position' => 1]);

    $detailsComponent = Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->set('title', 'Intruder title');
    $this->actingAs($otherAccount);
    $detailsComponent->call('saveDetails')->assertStatus(404);

    $optionComponent = Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->set('optionName', 'Intruder Option')
        ->set('optionCapacity', '1');
    $this->actingAs($otherAccount);
    $optionComponent->call('addOption')->assertStatus(404);

    $removeComponent = Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet]);
    $this->actingAs($otherAccount);
    $removeComponent->call('removeOption', $option->id)->assertStatus(404);

    $publishComponent = Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet]);
    $this->actingAs($otherAccount);
    $publishComponent->call('publish')->assertStatus(404);

    expect($sheet->refresh())
        ->title->not->toBe('Intruder title')
        ->state->toBe(Sheet::STATE_DRAFT)
        ->and($sheet->options()->pluck('name')->all())->toBe(['Owner Option']);
});

test('Owner cannot save Sheet details without a title', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    Livewire::test('pages::sheets.edit', ['sheet' => $sheet])
        ->set('title', '   ')
        ->call('saveDetails')
        ->assertHasErrors(['title']);

    expect($sheet->refresh()->title)->not->toBe('');
});

test('other Account cannot rehydrate a stale Draft component', function () {
    $owner = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'description' => 'Owner-only planning details.',
    ]);

    $propertyComponent = Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet])
        ->assertSee('Owner-only planning details.');
    $this->actingAs($otherAccount);
    $propertyComponent
        ->set('title', 'Intruder title')
        ->assertStatus(404)
        ->assertDontSee('Owner-only planning details.');

    $cancelComponent = Livewire::actingAs($owner)
        ->test('pages::sheets.edit', ['sheet' => $sheet]);
    $this->actingAs($otherAccount);
    $cancelComponent
        ->call('cancelEditingOption')
        ->assertStatus(404)
        ->assertDontSee('Owner-only planning details.');
});
