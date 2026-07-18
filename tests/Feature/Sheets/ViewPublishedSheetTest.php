<?php

use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

test('Option claimed count is a persistent non-negative counter', function () {
    $sheet = Sheet::factory()->create();
    $option = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'position' => 1,
    ]);

    expect($option->refresh()->claimed_count)->toBe(0)
        ->and(fn () => DB::table('options')->insert([
            'sheet_id' => $sheet->id,
            'name' => 'Invalid counter',
            'capacity' => 1,
            'claimed_count' => -1,
            'position' => 2,
        ]))->toThrow(QueryException::class);
});

test('Published Sheet UUID is available with robots directives', function (bool $authenticated) {
    $sheet = Sheet::factory()->create([
        'title' => 'Saturday garden workday',
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $sheet->options()->create([
        'name' => 'Morning setup',
        'capacity' => 2,
        'position' => 1,
    ]);

    if ($authenticated) {
        $this->actingAs(Account::factory()->create());
    }

    $this->get('/sheets/'.$sheet->public_id)
        ->assertOk()
        ->assertSee('Saturday garden workday')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSeeHtml('<meta name="robots" content="noindex, nofollow">');
})->with([
    'public access' => false,
    'authenticated access' => true,
]);

test('Draft Sheet and unknown UUID return the same generic unavailable response', function () {
    $draft = Sheet::factory()->create(['title' => 'Private planning notes']);

    $draftResponse = $this->get('/sheets/'.$draft->public_id);
    $unknownResponse = $this->get('/sheets/00000000-0000-4000-8000-000000000000');

    $draftResponse
        ->assertNotFound()
        ->assertSee('This signup sheet is unavailable.')
        ->assertDontSee('Private planning notes');
    $unknownResponse
        ->assertNotFound()
        ->assertSee('This signup sheet is unavailable.');

    expect($draftResponse->getContent())->toBe($unknownResponse->getContent());
});

test('Published Sheet shows localized details and its open state', function () {
    $this->travelTo(Carbon::parse('2026-09-01 12:00:00 UTC'));

    $sheet = Sheet::factory()->create([
        'title' => 'Harvest supper',
        'description' => 'Choose something useful to bring.',
        'event_at' => Carbon::parse('2026-09-05 17:30:00 America/Los_Angeles')->utc(),
        'location' => 'North field barn',
        'deadline_at' => Carbon::parse('2026-09-04 23:59:00 America/Los_Angeles')->utc(),
        'timezone' => 'America/Los_Angeles',
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $sheet->options()->create([
        'name' => 'Place settings',
        'capacity' => 2,
        'position' => 1,
    ]);

    $this->get('/sheets/'.$sheet->public_id)
        ->assertOk()
        ->assertSee('Choose something useful to bring.')
        ->assertSee('North field barn')
        ->assertSee('Sep 5, 2026 at 5:30 PM PDT')
        ->assertSee('Sep 4, 2026 at 11:59 PM PDT')
        ->assertSee('Open for signups');
});

test('Published Sheet reports a passed deadline as closed in text', function () {
    $this->travelTo(Carbon::parse('2026-09-05 12:00:00 UTC'));

    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
        'deadline_at' => Carbon::parse('2026-09-05 11:59:00 UTC'),
    ]);
    $sheet->options()->create([
        'name' => 'Already closed',
        'capacity' => 1,
        'position' => 1,
    ]);

    $this->get('/sheets/'.$sheet->public_id)
        ->assertOk()
        ->assertSee('Closed to signups')
        ->assertDontSee('Open for signups')
        ->assertSeeHtml('role="status"');
});

test('Published Sheet lists Options in Owner order with capacity totals', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $sheet->options()->create([
        'name' => 'Evening lanterns',
        'capacity' => 11,
        'claimed_count' => 4,
        'position' => 2,
    ]);
    $sheet->options()->create([
        'name' => 'Morning bread',
        'description' => 'Bring a sliced loaf.',
        'capacity' => 7,
        'claimed_count' => 2,
        'position' => 1,
    ]);

    $this->get('/sheets/'.$sheet->public_id)
        ->assertOk()
        ->assertSeeInOrder([
            'Morning bread',
            'Bring a sliced loaf.',
            'Total',
            '7',
            'Claimed',
            '2',
            'Remaining',
            '5',
            'Evening lanterns',
            'Total',
            '11',
            'Claimed',
            '4',
            'Remaining',
            '7',
        ]);
});

test('Full and Over-Capacity Options remain visible with textual unavailable states', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $sheet->options()->create([
        'name' => 'Full pie table',
        'capacity' => 3,
        'claimed_count' => 3,
        'position' => 1,
    ]);
    $sheet->options()->create([
        'name' => 'Over-capacity chairs',
        'capacity' => 2,
        'claimed_count' => 4,
        'position' => 2,
    ]);

    $this->get('/sheets/'.$sheet->public_id)
        ->assertOk()
        ->assertSeeInOrder([
            'Full pie table',
            'Full — unavailable',
            'Over-capacity chairs',
            'Over capacity — unavailable',
        ]);
});

test('Published Sheet markup exposes only public content and UUID addressing', function () {
    $owner = Account::factory()->create([
        'name' => 'Private Owner Name',
        'email' => 'private-owner@example.test',
    ]);
    $owner->forceFill(['id' => 731001])->save();

    $sheet = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Harvest <script>window.sheetLeak = true</script>',
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $sheet->forceFill(['id' => 731002])->save();
    $option = $sheet->options()->create([
        'name' => 'Serving spoons',
        'description' => '<img src=x onerror="window.optionLeak=true">',
        'capacity' => 2,
        'position' => 1,
    ]);
    $option->forceFill(['id' => 731003])->save();

    $url = route('sheets.show', $sheet, absolute: false);
    $response = $this->get($url);

    expect($url)->toBe('/sheets/'.$sheet->public_id);
    $response
        ->assertOk()
        ->assertSee($sheet->title)
        ->assertSee($option->description)
        ->assertDontSeeHtml('<script>window.sheetLeak = true</script>')
        ->assertDontSeeHtml('<img src=x onerror="window.optionLeak=true">')
        ->assertDontSee('Private Owner Name')
        ->assertDontSee('private-owner@example.test')
        ->assertDontSee('731001')
        ->assertDontSee('731002')
        ->assertDontSee('731003')
        ->assertSeeHtml('wire:name="complete-open-signup"');
});

test('Published Sheet has semantic keyboard and mobile-first structure', function () {
    $sheet = Sheet::factory()->create([
        'event_at' => Carbon::parse('2026-09-05 17:30:00 America/Los_Angeles')->utc(),
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'position' => 1,
    ]);

    $response = $this->get('/sheets/'.$sheet->public_id);

    $response
        ->assertOk()
        ->assertSeeHtml('<a href="#main-content"')
        ->assertSee('Skip to signup sheet')
        ->assertSeeHtml('<main id="main-content"')
        ->assertSeeHtml('aria-labelledby="options-title"')
        ->assertSeeHtml('<ol')
        ->assertSeeHtml('<dl')
        ->assertSeeHtml('<time datetime="')
        ->assertSeeHtml('focus-visible:')
        ->assertSeeHtml('sm:grid-cols-');

    expect(substr_count($response->getContent(), '<h1'))->toBe(1);
});
