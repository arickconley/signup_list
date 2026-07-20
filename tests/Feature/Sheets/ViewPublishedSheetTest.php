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

test('Published Sheet UUID remains viewable but exposes no signup or edit path at and after its deadline', function (string $deadline) {
    $this->travelTo(Carbon::parse('2026-09-05 12:00:00 UTC'));

    try {
        $owner = Account::factory()->create(['timezone' => 'America/Los_Angeles']);
        $sheet = Sheet::factory()->for($owner, 'owner')->create([
            'title' => 'Closed boundary field day',
            'description' => 'Public event details remain reviewable.',
            'state' => Sheet::STATE_PUBLISHED,
            'selection_maximum' => 1,
            'deadline_at' => Carbon::parse($deadline),
            'timezone' => 'America/Los_Angeles',
        ]);
        $sheet->options()->create([
            'name' => 'Already closed',
            'capacity' => 1,
            'position' => 1,
        ]);
        $signup = $sheet->signups()->create(['name_snapshot' => 'Private Participant']);

        $this->get('/sheets/'.$sheet->public_id)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSeeHtml('<meta name="robots" content="noindex, nofollow">')
            ->assertSee('Closed boundary field day')
            ->assertSee('Public event details remain reviewable.')
            ->assertSee('Closed to signups')
            ->assertDontSee('Open for signups')
            ->assertSeeHtml('role="status"')
            ->assertDontSeeHtml('wire:submit="complete"')
            ->assertDontSee('Complete Signup')
            ->assertDontSeeHtml('href="'.route('sheets.edit', $sheet, absolute: false).'"')
            ->assertDontSeeHtml('href="'.route('signups.edit', $signup, absolute: false).'"')
            ->assertDontSee('Private Participant');
    } finally {
        $this->travelBack();
    }
})->with([
    'exact deadline boundary' => '2026-09-05 12:00:00 UTC',
    'past deadline' => '2026-09-05 11:59:00 UTC',
]);

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

test('Open Participation puts Claim on the Option ledger row without duplicating the Option list', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'position' => 1,
    ]);

    $response = $this->get(route('sheets.show', $sheet))->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $claimButton = $xpath->query("//article[.//h3[normalize-space(.)='Welcome table']]//*[@data-option-controls]//button[.//span[normalize-space(.)='Claim']]")->item(0);
    $node = $claimButton;
    $hasAlpineScope = false;

    while ($node instanceof DOMElement) {
        if ($node->hasAttribute('x-data')) {
            $hasAlpineScope = true;

            break;
        }

        $node = $node->parentNode;
    }

    expect($xpath->query("//h3[normalize-space(.)='Welcome table']"))->toHaveCount(1)
        ->and($xpath->query("//article[.//h3[normalize-space(.)='Welcome table']]//*[@data-option-controls]//button[.//span[normalize-space(.)='Claim']]"))->toHaveCount(1)
        ->and($hasAlpineScope)->toBeTrue()
        ->and($xpath->query("//*[normalize-space(.)='Available Options']"))->toHaveCount(0);
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

test('Published Sheet shows consented Signup snapshots without Account profile fallback', function () {
    $account = Account::factory()->create([
        'name' => 'Changed Account Name',
        'email' => 'changed-account@example.test',
        'phone' => '555-9999',
    ]);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
        'name_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'email_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'phone_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Submitted Participant',
        'email_snapshot' => 'submitted@example.test',
        'phone_snapshot' => '555-0102',
        'name_consent' => true,
        'email_consent' => true,
        'phone_consent' => true,
    ]);
    $signup->forceFill(['account_id' => $account->id])->save();
    $signup->optionClaims()->create(['option_id' => $option->id]);

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('Submitted Participant')
        ->assertSee('submitted@example.test')
        ->assertSee('555-0102')
        ->assertDontSee('Changed Account Name')
        ->assertDontSee('changed-account@example.test')
        ->assertDontSee('555-9999');
});

test('participant-visible name without name consent renders initials instead of the full snapshot', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
        'name_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Zelda Quince',
        'name_consent' => false,
    ]);
    $signup->optionClaims()->create(['option_id' => $option->id]);

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('ZQ')
        ->assertDontSee('Zelda Quince');
});

test('Owner-only name suppresses identity while independently eligible consented contacts render', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
        'name_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'email_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'phone_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Secret Roster Person',
        'email_snapshot' => 'independent-contact@example.test',
        'phone_snapshot' => '555-0186',
        'name_consent' => true,
        'email_consent' => true,
        'phone_consent' => true,
    ]);
    $signup->optionClaims()->create(['option_id' => $option->id]);

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('independent-contact@example.test')
        ->assertSee('555-0186')
        ->assertDontSee('Secret Roster Person')
        ->assertDontSee('SRP');
});

test('public contact rendering requires matching eligibility and consent for every Participant type', function (
    string $field,
    string $participantType,
    string $visibility,
    bool $consent,
    bool $shouldRender,
) {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
        'name_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'email_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'phone_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        $field.'_visibility' => $visibility,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Matrix Option',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Matrix Hidden Person',
        'email_snapshot' => 'matrix-email@example.test',
        'phone_snapshot' => '555-0168',
        'email_consent' => $field === 'email' && $consent,
        'phone_consent' => $field === 'phone' && $consent,
    ]);
    $signup->optionClaims()->create(['option_id' => $option->id]);

    if ($participantType === 'account') {
        $account = Account::factory()->create();
        $signup->forceFill(['account_id' => $account->id])->save();
    } elseif ($participantType === 'pending') {
        $signup->pendingAccountAssociation()->create([
            'account_id' => Account::factory()->unverified()->create()->id,
        ]);
    }

    $response = $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('Matrix Option')
        ->assertDontSee('Matrix Hidden Person');
    $contact = $field === 'email' ? 'matrix-email@example.test' : '555-0168';

    if ($shouldRender) {
        $response->assertSee($contact);
    } else {
        $response->assertDontSee($contact);
    }
})->with([
    'email Account Owner-only refused' => ['email', 'account', Sheet::VISIBILITY_OWNER_ONLY, false, false],
    'email Account Owner-only consented' => ['email', 'account', Sheet::VISIBILITY_OWNER_ONLY, true, false],
    'email Account eligible refused' => ['email', 'account', Sheet::VISIBILITY_PARTICIPANTS, false, false],
    'email Account eligible consented' => ['email', 'account', Sheet::VISIBILITY_PARTICIPANTS, true, true],
    'email pending Owner-only refused' => ['email', 'pending', Sheet::VISIBILITY_OWNER_ONLY, false, false],
    'email pending Owner-only consented' => ['email', 'pending', Sheet::VISIBILITY_OWNER_ONLY, true, false],
    'email pending eligible refused' => ['email', 'pending', Sheet::VISIBILITY_PARTICIPANTS, false, false],
    'email pending eligible consented' => ['email', 'pending', Sheet::VISIBILITY_PARTICIPANTS, true, true],
    'email Unregistered Owner-only refused' => ['email', 'unregistered', Sheet::VISIBILITY_OWNER_ONLY, false, false],
    'email Unregistered Owner-only consented' => ['email', 'unregistered', Sheet::VISIBILITY_OWNER_ONLY, true, false],
    'email Unregistered eligible refused' => ['email', 'unregistered', Sheet::VISIBILITY_PARTICIPANTS, false, false],
    'email Unregistered eligible consented' => ['email', 'unregistered', Sheet::VISIBILITY_PARTICIPANTS, true, true],
    'phone Account Owner-only refused' => ['phone', 'account', Sheet::VISIBILITY_OWNER_ONLY, false, false],
    'phone Account Owner-only consented' => ['phone', 'account', Sheet::VISIBILITY_OWNER_ONLY, true, false],
    'phone Account eligible refused' => ['phone', 'account', Sheet::VISIBILITY_PARTICIPANTS, false, false],
    'phone Account eligible consented' => ['phone', 'account', Sheet::VISIBILITY_PARTICIPANTS, true, true],
    'phone pending Owner-only refused' => ['phone', 'pending', Sheet::VISIBILITY_OWNER_ONLY, false, false],
    'phone pending Owner-only consented' => ['phone', 'pending', Sheet::VISIBILITY_OWNER_ONLY, true, false],
    'phone pending eligible refused' => ['phone', 'pending', Sheet::VISIBILITY_PARTICIPANTS, false, false],
    'phone pending eligible consented' => ['phone', 'pending', Sheet::VISIBILITY_PARTICIPANTS, true, true],
    'phone Unregistered Owner-only refused' => ['phone', 'unregistered', Sheet::VISIBILITY_OWNER_ONLY, false, false],
    'phone Unregistered Owner-only consented' => ['phone', 'unregistered', Sheet::VISIBILITY_OWNER_ONLY, true, false],
    'phone Unregistered eligible refused' => ['phone', 'unregistered', Sheet::VISIBILITY_PARTICIPANTS, false, false],
    'phone Unregistered eligible consented' => ['phone', 'unregistered', Sheet::VISIBILITY_PARTICIPANTS, true, true],
]);

test('public name rendering applies eligibility and consent for every Participant type', function (
    string $participantType,
    string $visibility,
    bool $consent,
    ?string $expectedName,
) {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
        'name_visibility' => $visibility,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Name Matrix Option',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Nova Meridian',
        'name_consent' => $consent,
    ]);
    $signup->optionClaims()->create(['option_id' => $option->id]);

    if ($participantType === 'account') {
        $account = Account::factory()->create();
        $signup->forceFill(['account_id' => $account->id])->save();
    } elseif ($participantType === 'pending') {
        $signup->pendingAccountAssociation()->create([
            'account_id' => Account::factory()->unverified()->create()->id,
        ]);
    }

    $response = $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('Name Matrix Option');

    if ($expectedName === 'Nova Meridian') {
        $response
            ->assertSee('Nova Meridian')
            ->assertDontSee('>NM<', escape: false);
    } elseif ($expectedName === 'NM') {
        $response
            ->assertSee('NM')
            ->assertDontSee('Nova Meridian');
    } else {
        $response
            ->assertDontSee('Nova Meridian')
            ->assertDontSee('>NM<', escape: false);
    }
})->with([
    'Account Owner-only refused' => ['account', Sheet::VISIBILITY_OWNER_ONLY, false, null],
    'Account Owner-only consented' => ['account', Sheet::VISIBILITY_OWNER_ONLY, true, null],
    'Account eligible refused' => ['account', Sheet::VISIBILITY_PARTICIPANTS, false, 'NM'],
    'Account eligible consented' => ['account', Sheet::VISIBILITY_PARTICIPANTS, true, 'Nova Meridian'],
    'pending Owner-only refused' => ['pending', Sheet::VISIBILITY_OWNER_ONLY, false, null],
    'pending Owner-only consented' => ['pending', Sheet::VISIBILITY_OWNER_ONLY, true, null],
    'pending eligible refused' => ['pending', Sheet::VISIBILITY_PARTICIPANTS, false, 'NM'],
    'pending eligible consented' => ['pending', Sheet::VISIBILITY_PARTICIPANTS, true, 'Nova Meridian'],
    'Unregistered Owner-only refused' => ['unregistered', Sheet::VISIBILITY_OWNER_ONLY, false, null],
    'Unregistered Owner-only consented' => ['unregistered', Sheet::VISIBILITY_OWNER_ONLY, true, null],
    'Unregistered eligible refused' => ['unregistered', Sheet::VISIBILITY_PARTICIPANTS, false, 'NM'],
    'Unregistered eligible consented' => ['unregistered', Sheet::VISIBILITY_PARTICIPANTS, true, 'Nova Meridian'],
]);

test('future Open Participation keeps identity fields out of the ledger until Claim', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
        'name_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'email_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'phone_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
    ]);
    $sheet->options()->create([
        'name' => 'Future Signup Option',
        'capacity' => 1,
        'position' => 1,
    ]);

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('Claim Future Signup Option')
        ->assertDontSee('Visibility Consent')
        ->assertDontSee('Share full name')
        ->assertDontSee('Share email')
        ->assertDontSee('Share phone')
        ->assertDontSee('Your name');
});

test('tightening visibility removes previously consented snapshots immediately', function () {
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
        'name_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'email_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
        'phone_visibility' => Sheet::VISIBILITY_PARTICIPANTS,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Tightening Option',
        'capacity' => 1,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Tightened Private Person',
        'email_snapshot' => 'tightened@example.test',
        'phone_snapshot' => '555-0154',
        'name_consent' => true,
        'email_consent' => true,
        'phone_consent' => true,
    ]);
    $signup->optionClaims()->create(['option_id' => $option->id]);

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSee('Tightened Private Person')
        ->assertSee('tightened@example.test')
        ->assertSee('555-0154');

    $sheet->update([
        'name_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'email_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
        'phone_visibility' => Sheet::VISIBILITY_OWNER_ONLY,
    ]);

    $this->get(route('sheets.show', $sheet))
        ->assertOk()
        ->assertSeeInOrder(['Tightening Option', 'Claimed', '1'])
        ->assertDontSee('Tightened Private Person')
        ->assertDontSee('tightened@example.test')
        ->assertDontSee('555-0154');
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
