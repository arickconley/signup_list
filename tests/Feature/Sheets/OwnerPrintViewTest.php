<?php

use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

test('only the Owner can open the default-private Participant Print View', function () {
    $owner = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Private volunteer roster',
        'state' => Sheet::STATE_PUBLISHED,
    ]);
    $sheet->signups()->create([
        'name_snapshot' => 'Submitted Participant',
        'email_snapshot' => 'private@example.test',
        'phone_snapshot' => '555-0104',
    ]);

    $url = '/sheets/'.$sheet->public_id.'/signups/print';

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
        ->assertSee('Grouped by Participant')
        ->assertDontSee('private@example.test')
        ->assertDontSee('555-0104')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSeeHtml('<meta name="robots" content="noindex, nofollow">');
});

test('Owner opts into submitted email while phone remains private in Participant grouping', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Submitted Participant',
        'email_snapshot' => 'owner-copy@example.test',
        'phone_snapshot' => '555-0199',
        'email_consent' => false,
        'phone_consent' => false,
    ]);
    $signup->optionClaims()->create(['option_id' => $option->id]);

    $this->actingAs($owner)
        ->get(route('sheets.signups.print', $sheet).'?email=1')
        ->assertOk()
        ->assertSee('Grouped by Participant')
        ->assertSeeHtml('<table')
        ->assertSeeHtml('<th scope="col">Participant</th>')
        ->assertSeeHtml('<th scope="col">Option Claims</th>')
        ->assertSeeHtml('<th scope="col">Email</th>')
        ->assertSee('Submitted Participant')
        ->assertSee('owner-copy@example.test')
        ->assertSee('Welcome table')
        ->assertDontSeeHtml('<th scope="col">Phone</th>')
        ->assertDontSee('555-0199');
});

test('Owner opts into submitted phone while email remains private in Participant grouping', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Meal delivery',
        'capacity' => 2,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Phone Participant',
        'email_snapshot' => 'hidden-email@example.test',
        'phone_snapshot' => '555-0177',
        'email_consent' => false,
        'phone_consent' => false,
    ]);
    $signup->optionClaims()->create(['option_id' => $option->id]);

    $this->actingAs($owner)
        ->get(route('sheets.signups.print', $sheet).'?phone=1')
        ->assertOk()
        ->assertSee('Grouped by Participant')
        ->assertSeeHtml('<th scope="col">Participant</th>')
        ->assertSee('Phone Participant')
        ->assertSee('Meal delivery')
        ->assertSeeHtml('<th scope="col">Phone</th>')
        ->assertSee('555-0177')
        ->assertDontSeeHtml('<th scope="col">Email</th>')
        ->assertDontSee('hidden-email@example.test');
});

test('Owner groups Print View by Option with enabled contact columns', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
    ]);
    $unclaimedOption = $sheet->options()->create([
        'name' => 'Setup supplies',
        'capacity' => 2,
        'position' => 1,
    ]);
    $claimedOption = $sheet->options()->create([
        'name' => 'Meal delivery',
        'capacity' => 2,
        'position' => 2,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Option Participant',
        'email_snapshot' => 'option-participant@example.test',
        'phone_snapshot' => '555-0122',
        'email_consent' => false,
        'phone_consent' => false,
    ]);
    $signup->optionClaims()->create(['option_id' => $claimedOption->id]);

    $response = $this->actingAs($owner)
        ->get(route('sheets.signups.print', $sheet).'?group=option&email=1&phone=1');

    $response
        ->assertOk()
        ->assertSee('Grouped by Option')
        ->assertDontSee('Grouped by Participant')
        ->assertSeeHtml('<table')
        ->assertSeeHtml('<th scope="col">Option</th>')
        ->assertSeeHtml('<th scope="col">Participants</th>')
        ->assertSeeHtml('<th scope="col">Email</th>')
        ->assertSeeHtml('<th scope="col">Phone</th>')
        ->assertSeeInOrder([
            $unclaimedOption->name,
            'No Option Claims',
            $claimedOption->name,
            'Option Participant',
            'option-participant@example.test',
            '555-0122',
        ]);

    expect(substr_count($response->getContent(), '<table'))->toBe(1);
});

test('Print View includes Sheet event context in its timezone', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Neighborhood volunteer day',
        'event_at' => Carbon::parse('2026-09-06 00:30:00 UTC'),
        'location' => 'Northside Community Center',
        'deadline_at' => Carbon::parse('2026-09-05 06:59:00 UTC'),
        'timezone' => 'America/Los_Angeles',
        'state' => Sheet::STATE_PUBLISHED,
    ]);

    $this->actingAs($owner)
        ->get(route('sheets.signups.print', $sheet))
        ->assertOk()
        ->assertSee('Neighborhood volunteer day')
        ->assertSeeHtml('<dl')
        ->assertSee('Event')
        ->assertSeeHtml('<time datetime="2026-09-06T00:30:00+00:00">')
        ->assertSee('Sep 5, 2026 at 5:30 PM PDT')
        ->assertSee('Location')
        ->assertSee('Northside Community Center')
        ->assertSee('Signup deadline')
        ->assertSeeHtml('<time datetime="2026-09-05T06:59:00+00:00">')
        ->assertSee('Sep 4, 2026 at 11:59 PM PDT');
});

test('both Print groupings show current Option totals and Over-Capacity warnings', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 2,
    ]);
    $full = $sheet->options()->create([
        'name' => 'Full breakfast',
        'capacity' => 2,
        'position' => 1,
    ]);
    $overCapacity = $sheet->options()->create([
        'name' => 'Over-Capacity chairs',
        'capacity' => 1,
        'position' => 2,
    ]);
    $available = $sheet->options()->create([
        'name' => 'Available drinks',
        'capacity' => 3,
        'position' => 3,
    ]);

    $firstSignup = $sheet->signups()->create(['name_snapshot' => 'First Participant']);
    $firstSignup->optionClaims()->createMany([
        ['option_id' => $full->id],
        ['option_id' => $overCapacity->id],
    ]);
    $secondSignup = $sheet->signups()->create(['name_snapshot' => 'Second Participant']);
    $secondSignup->optionClaims()->createMany([
        ['option_id' => $full->id],
        ['option_id' => $overCapacity->id],
    ]);
    $thirdSignup = $sheet->signups()->create(['name_snapshot' => 'Third Participant']);
    $thirdSignup->optionClaims()->create(['option_id' => $available->id]);

    foreach ([
        'Participant' => '',
        'Option' => '?group=option',
    ] as $grouping => $query) {
        $response = $this->actingAs($owner)
            ->get(route('sheets.signups.print', $sheet).$query)
            ->assertOk()
            ->assertSee('Grouped by '.$grouping);

        $document = new DOMDocument;
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        $items = (new DOMXPath($document))
            ->query('//ol[@aria-label="Option capacity summary"]/li');

        expect($items->length)->toBe(3);

        $summaries = [];

        foreach ($items as $item) {
            $summaries[] = preg_replace('/\s+/u', ' ', trim($item->textContent));
        }

        expect($summaries[0])
            ->toBe('Full breakfast Capacity 2 Claimed 2 Remaining 0')
            ->not->toContain('Over-Capacity')
            ->and($summaries[1])
            ->toBe('Over-Capacity chairs Capacity 1 Claimed 2 Remaining 0 Over-Capacity — 1 over')
            ->and($summaries[2])
            ->toBe('Available drinks Capacity 3 Claimed 1 Remaining 2')
            ->not->toContain('Over-Capacity');
    }
});

test('Owner Signup View exposes accessible Print View options', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
    ]);

    $response = $this->actingAs($owner)
        ->get(route('sheets.signups', $sheet))
        ->assertOk();

    $document = new DOMDocument;
    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $document->loadHTML($response->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);
    $xpath = new DOMXPath($document);

    $forms = $xpath->query(sprintf(
        '//form[@method="GET" and @action="%s" and @target="_blank"]',
        route('sheets.signups.print', $sheet, absolute: false),
    ));

    expect($forms->length)->toBe(1);

    $form = $forms->item(0);
    expect($form)->toBeInstanceOf(DOMElement::class);
    assert($form instanceof DOMElement);

    expect($form->getAttribute('class'))
        ->toContain('grid gap-4')
        ->toContain('sm:grid-cols-');
    expect($xpath->query('.//fieldset/legend[normalize-space()="Print View options"]', $form)->length)->toBe(1)
        ->and($xpath->query('.//label[@for="print-grouping"]', $form)->length)->toBe(1)
        ->and($xpath->query('.//select[@id="print-grouping" and @name="group"]', $form)->length)->toBe(1)
        ->and($xpath->query('.//select[@id="print-grouping"]/option[@value="participant" and normalize-space()="Participant"]', $form)->length)->toBe(1)
        ->and($xpath->query('.//select[@id="print-grouping"]/option[@value="option" and normalize-space()="Option"]', $form)->length)->toBe(1)
        ->and($xpath->query('.//label[@for="print-email"]', $form)->length)->toBe(1)
        ->and($xpath->query('.//input[@id="print-email" and @type="checkbox" and @name="email" and @value="1" and not(@checked)]', $form)->length)->toBe(1)
        ->and($xpath->query('.//label[@for="print-phone"]', $form)->length)->toBe(1)
        ->and($xpath->query('.//input[@id="print-phone" and @type="checkbox" and @name="phone" and @value="1" and not(@checked)]', $form)->length)->toBe(1)
        ->and($xpath->query('.//button[@type="submit" and normalize-space()="Open Print View"]', $form)->length)->toBe(1);
});

test('Print View screen controls are removed when printing', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
    ]);

    $response = $this->actingAs($owner)
        ->get(route('sheets.signups.print', $sheet))
        ->assertOk()
        ->assertDontSeeHtml('<nav');

    $document = new DOMDocument;
    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $document->loadHTML($response->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);
    $xpath = new DOMXPath($document);

    $controls = $xpath->query('//*[@data-print-chrome]');
    expect($controls->length)->toBe(1);

    $control = $controls->item(0);
    expect($control)->toBeInstanceOf(DOMElement::class);
    assert($control instanceof DOMElement);

    expect($xpath->query(sprintf(
        './/a[@href="%s" and normalize-space()="Back to Signup View"]',
        route('sheets.signups', $sheet, absolute: false),
    ), $control)->length)->toBe(1)
        ->and($xpath->query('.//button[@type="button" and @onclick="window.print()" and normalize-space()="Print"]', $control)->length)->toBe(1);

    expect($response->getContent())
        ->toContain('<style media="print">')
        ->toContain('nav,')
        ->toContain('button,')
        ->toContain('[data-print-chrome]')
        ->toContain('display: none !important;');
});

test('empty Print Views stay HTML-only and explain missing data', function () {
    Storage::fake('local');

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
    ]);
    $states = [];

    foreach ([
        'Participant' => ['', 'No Signups yet'],
        'Option' => ['?group=option', 'No Options yet'],
    ] as $grouping => [$query, $message]) {
        $response = $this->actingAs($owner)
            ->get(route('sheets.signups.print', $sheet).$query)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        expect($response->headers->has('Content-Disposition'))->toBeFalse();

        $document = new DOMDocument;
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);
        $xpath = new DOMXPath($document);
        $statuses = [];

        foreach ($xpath->query('//*[@role="status"]') as $status) {
            $statuses[] = preg_replace('/\s+/u', ' ', trim($status->textContent)) ?? '';
        }

        $states[$grouping] = [
            'tables' => $xpath->query('//table')->length,
            'statuses' => $statuses,
            'capacity_summaries' => $xpath->query('//ol[@aria-label="Option capacity summary"]')->length,
        ];
    }

    expect($states)->toBe([
        'Participant' => [
            'tables' => 0,
            'statuses' => ['No Signups yet'],
            'capacity_summaries' => 0,
        ],
        'Option' => [
            'tables' => 0,
            'statuses' => ['No Options yet'],
            'capacity_summaries' => 0,
        ],
    ]);

    $files = Storage::disk('local')->allFiles();

    expect($files)->toBeEmpty()
        ->and(collect($files)->contains(fn (string $file): bool => str_ends_with($file, '.pdf')))->toBeFalse();
});
