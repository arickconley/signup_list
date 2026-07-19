<?php

use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated accounts can visit the dashboard', function () {
    $account = Account::factory()->create();
    $this->actingAs($account);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('Owner dashboard separates owned Signup Sheets by lifecycle', function () {
    $this->travelTo(Carbon::parse('2026-07-19 12:00:00 UTC'));

    $owner = Account::factory()->create();
    $draft = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Draft community supper',
        'state' => Sheet::STATE_DRAFT,
    ]);
    $open = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Open community supper',
        'state' => Sheet::STATE_PUBLISHED,
        'deadline_at' => now()->addDay(),
    ]);
    $closed = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Closed community supper',
        'state' => Sheet::STATE_CLOSED,
    ]);
    $deadlineClosed = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Deadline closed supper',
        'state' => Sheet::STATE_PUBLISHED,
        'deadline_at' => now(),
    ]);
    $archived = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Archived community supper',
        'state' => Sheet::STATE_ARCHIVED,
    ]);

    $response = $this->actingAs($owner)->get(route('dashboard'))->assertOk();

    $document = new DOMDocument;
    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $document->loadHTML($response->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);
    $xpath = new DOMXPath($document);

    $sectionText = fn (string $labelledBy): string => preg_replace(
        '/\s+/u',
        ' ',
        trim($xpath->query("//section[@aria-labelledby='{$labelledBy}']")->item(0)?->textContent ?? ''),
    );

    expect($sectionText('draft-sheets-title'))
        ->toContain($draft->title)
        ->not->toContain($open->title, $closed->title, $deadlineClosed->title, $archived->title)
        ->and($sectionText('open-sheets-title'))
        ->toContain($open->title)
        ->not->toContain($draft->title, $closed->title, $deadlineClosed->title, $archived->title)
        ->and($sectionText('closed-sheets-title'))
        ->toContain($closed->title, $deadlineClosed->title)
        ->not->toContain($draft->title, $open->title, $archived->title)
        ->and($sectionText('archived-sheets-title'))
        ->toContain($archived->title)
        ->not->toContain($draft->title, $open->title, $closed->title, $deadlineClosed->title);
});

test('Owner dashboard exposes only actions valid for each Signup Sheet lifecycle', function () {
    $owner = Account::factory()->create();
    $draft = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Action matrix draft',
        'state' => Sheet::STATE_DRAFT,
    ]);
    $open = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Action matrix open',
        'state' => Sheet::STATE_PUBLISHED,
        'deadline_at' => now()->addDay(),
    ]);
    $closed = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Action matrix closed',
        'state' => Sheet::STATE_CLOSED,
    ]);
    $archived = Sheet::factory()->for($owner, 'owner')->create([
        'title' => 'Action matrix archived',
        'state' => Sheet::STATE_ARCHIVED,
    ]);

    $response = $this->actingAs($owner)->get(route('dashboard'))->assertOk();

    $document = new DOMDocument;
    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $document->loadHTML($response->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);
    $xpath = new DOMXPath($document);

    $actionsFor = function (string $title) use ($xpath): array {
        $card = $xpath->query("//article[.//h3[normalize-space()='{$title}']]")->item(0);
        $actions = [];

        foreach ($xpath->query('.//nav//a', $card) as $link) {
            $actions[preg_replace('/\s+/u', ' ', trim($link->textContent))] = $link->getAttribute('href');
        }

        return $actions;
    };

    expect($actionsFor($draft->title))->toBe([
        'Edit Sheet' => route('sheets.edit', $draft, absolute: false),
        'Duplicate Sheet' => route('sheets.edit', $draft, absolute: false).'#sheet-actions-title',
    ])->and($actionsFor($open->title))->toBe([
        'View Sheet' => route('sheets.show', $open, absolute: false),
        'Edit Sheet' => route('sheets.edit', $open, absolute: false),
        'View Signups' => route('sheets.signups', $open, absolute: false),
        'Close Sheet' => route('sheets.edit', $open, absolute: false).'#sheet-actions-title',
        'Archive Sheet' => route('sheets.edit', $open, absolute: false).'#sheet-actions-title',
        'Duplicate Sheet' => route('sheets.edit', $open, absolute: false).'#sheet-actions-title',
        'Print Signups' => route('sheets.signups.print', $open, absolute: false),
    ])->and($actionsFor($closed->title))->toBe([
        'View Sheet' => route('sheets.show', $closed, absolute: false),
        'Edit Sheet' => route('sheets.edit', $closed, absolute: false),
        'View Signups' => route('sheets.signups', $closed, absolute: false),
        'Reopen Sheet' => route('sheets.edit', $closed, absolute: false).'#sheet-actions-title',
        'Archive Sheet' => route('sheets.edit', $closed, absolute: false).'#sheet-actions-title',
        'Duplicate Sheet' => route('sheets.edit', $closed, absolute: false).'#sheet-actions-title',
        'Print Signups' => route('sheets.signups.print', $closed, absolute: false),
    ])->and($actionsFor($archived->title))->toBe([
        'View Signups' => route('sheets.signups', $archived, absolute: false),
        'Duplicate Sheet' => route('sheets.signups', $archived, absolute: false).'#sheet-actions-title',
        'Print Signups' => route('sheets.signups.print', $archived, absolute: false),
    ]);
});

test('Account dashboard hides Owner creation actions when the Account is ineligible', function () {
    $account = Account::factory()->create([
        'email' => 'owner@10minutemail.com',
    ]);
    $sheet = Sheet::factory()->for($account, 'owner')->create([
        'title' => 'Existing blocked-domain Sheet',
        'state' => Sheet::STATE_DRAFT,
    ]);

    $this->actingAs($account)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee($sheet->title)
        ->assertDontSeeHtml('href="'.route('sheets.create').'"')
        ->assertDontSee('Duplicate Sheet');
});

test('Account dashboard is unindexable and never leaks another Accounts Sheet data', function () {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();

    foreach ([
        Sheet::STATE_DRAFT,
        Sheet::STATE_PUBLISHED,
        Sheet::STATE_CLOSED,
        Sheet::STATE_ARCHIVED,
    ] as $state) {
        Sheet::factory()->for($otherAccount, 'owner')->create([
            'title' => 'Other Account private '.$state.' Sheet',
            'state' => $state,
            'deadline_at' => now()->addDay(),
        ]);
    }

    $otherJoinedSheet = Sheet::factory()->create([
        'title' => 'Other Account joined Sheet',
        'state' => Sheet::STATE_PUBLISHED,
    ]);
    $otherSignup = $otherJoinedSheet->signups()->create([
        'name_snapshot' => 'Other Account private participant',
    ]);
    $otherSignup->forceFill(['account_id' => $otherAccount->id])->save();

    $this->actingAs($account)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSeeHtml('<meta name="robots" content="noindex, nofollow">')
        ->assertDontSee('Other Account private')
        ->assertDontSee('Other Account joined Sheet');
});

test('Dashboard empty and populated states support mobile and keyboard navigation', function () {
    $account = Account::factory()->create();

    $emptyResponse = $this->actingAs($account)->get(route('dashboard'));

    $emptyResponse
        ->assertOk()
        ->assertSee('No owned Signup Sheets yet')
        ->assertSee('No joined Signup Sheets yet')
        ->assertSeeHtml('href="'.route('sheets.create').'"')
        ->assertSeeHtml('<main id="main-content"')
        ->assertSeeHtml('href="#main-content"');

    expect(substr_count($emptyResponse->getContent(), '<h1'))->toBe(1);

    $open = Sheet::factory()->for($account, 'owner')->create([
        'title' => 'Accessible mobile card',
        'state' => Sheet::STATE_PUBLISHED,
        'deadline_at' => now()->addDay(),
    ]);

    $populatedResponse = $this->get(route('dashboard'))->assertOk();

    $document = new DOMDocument;
    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $document->loadHTML($populatedResponse->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);
    $xpath = new DOMXPath($document);
    $actionNavigation = $xpath->query(
        "//article[.//h3[normalize-space()='{$open->title}']]/nav",
    )->item(0);

    expect($actionNavigation?->getAttribute('aria-label'))
        ->toBe('Actions for '.$open->title)
        ->and($xpath->query('.//a', $actionNavigation)->length)->toBeGreaterThan(0);

    foreach ($xpath->query('.//a', $actionNavigation) as $link) {
        expect($link->getAttribute('class'))
            ->toContain('min-h-11')
            ->toContain('focus-visible:');
    }

    $populatedResponse
        ->assertSeeHtml('grid gap-4 sm:grid-cols-2')
        ->assertSeeHtml('flex flex-wrap');
});

test('authenticated Sheet creation and management pages cannot be indexed', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    foreach ([
        route('sheets.create'),
        route('sheets.edit', $sheet),
    ] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSeeHtml('<meta name="robots" content="noindex, nofollow">');
    }
});

test('Owner dashboard lists only their archived Signup Sheets as private records', function () {
    $owner = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $archivedSheet = Sheet::factory()->create([
        'owner_id' => $owner->id,
        'title' => 'Archived Neighborhood Supper',
        'state' => Sheet::STATE_ARCHIVED,
    ]);
    $otherArchivedSheet = Sheet::factory()->create([
        'owner_id' => $otherAccount->id,
        'title' => 'Other Owner Archived Supper',
        'state' => Sheet::STATE_ARCHIVED,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Archived Signup Sheets')
        ->assertSee('Archived Neighborhood Supper')
        ->assertSee('Archived')
        ->assertSee('View Signups')
        ->assertSeeHtml('href="'.route('sheets.signups', $archivedSheet, absolute: false).'"')
        ->assertDontSee('Other Owner Archived Supper')
        ->assertDontSeeHtml('href="'.route('sheets.edit', $archivedSheet, absolute: false).'"')
        ->assertDontSee('Restore Sheet')
        ->assertDontSee('Delete Sheet');
});

test('newly verified Accounts missing required profile details are guided to profile settings', function () {
    $account = Account::factory()->passwordless()->create(['name' => null]);

    $this->actingAs($account)
        ->get(route('dashboard'))
        ->assertRedirect(route('profile.edit'));
});

test('verified Accounts missing a timezone are guided to profile settings', function () {
    $account = Account::factory()->create(['timezone' => null]);

    $this->actingAs($account)
        ->get(route('dashboard'))
        ->assertRedirect(route('profile.edit'));
});

test('verified Accounts complete their profile before using other settings', function () {
    $account = Account::factory()->create(['timezone' => null]);

    $this->actingAs($account)
        ->get(route('appearance.edit'))
        ->assertRedirect(route('profile.edit'));
});

test('Account dashboard lists only attached Signups on non-archived Signup Sheets and current Option Claims', function () {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();

    $attachedSheet = Sheet::factory()->create([
        'title' => 'Attached Garden Day',
        'state' => Sheet::STATE_PUBLISHED,
    ]);
    $attachedOption = $attachedSheet->options()->create([
        'name' => 'Welcome Table',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $attachedSignup = $attachedSheet->signups()->create([
        'name_snapshot' => 'Dashboard Participant',
        'email_snapshot' => $account->email,
    ]);
    $attachedSignup->forceFill(['account_id' => $account->id])->save();
    $attachedSignup->optionClaims()->create(['option_id' => $attachedOption->id]);

    $olderAttachedSheet = Sheet::factory()->create([
        'title' => 'Older Attached Sheet',
        'state' => Sheet::STATE_PUBLISHED,
    ]);
    $olderAttachedSignup = $olderAttachedSheet->signups()->create([
        'name_snapshot' => 'Older Dashboard Participant',
    ]);
    $olderAttachedSignup->forceFill([
        'account_id' => $account->id,
        'created_at' => now()->subDay(),
    ])->save();

    $pendingAssociationSheet = Sheet::factory()->create(['title' => 'Pending Association Sheet']);
    $signupWithPendingAccountAssociation = $pendingAssociationSheet->signups()->create([
        'name_snapshot' => 'Pending Participant',
        'email_snapshot' => 'pending-dashboard@example.com',
    ]);
    $signupWithPendingAccountAssociation->pendingAccountAssociation()->create(['account_id' => $account->id]);

    $archivedSheet = Sheet::factory()->create([
        'title' => 'Archived Private Sheet',
        'state' => Sheet::STATE_ARCHIVED,
    ]);
    $archivedSignup = $archivedSheet->signups()->create([
        'name_snapshot' => 'Archived Participant',
    ]);
    $archivedSignup->forceFill(['account_id' => $account->id])->save();

    $otherSheet = Sheet::factory()->create(['title' => 'Other Account Sheet']);
    $otherSignup = $otherSheet->signups()->create([
        'name_snapshot' => 'Other Participant',
    ]);
    $otherSignup->forceFill(['account_id' => $otherAccount->id])->save();

    Model::preventLazyLoading();

    try {
        $response = $this->actingAs($account)->get(route('dashboard'));
    } finally {
        Model::preventLazyLoading(false);
    }

    $response
        ->assertOk()
        ->assertSee('Your Signups')
        ->assertSee('Joined Signup Sheets')
        ->assertSeeHtml('<span class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700 dark:text-amber-400">Signup</span>')
        ->assertSee('Attached Garden Day')
        ->assertSee('Older Attached Sheet')
        ->assertSeeInOrder(['Attached Garden Day', 'Older Attached Sheet'])
        ->assertSee('Dashboard Participant')
        ->assertSee('Welcome Table')
        ->assertSeeHtml('href="'.route('sheets.show', $attachedSheet, absolute: false).'"')
        ->assertDontSee('Pending Association Sheet')
        ->assertDontSee('Archived Private Sheet')
        ->assertDontSee('Other Account Sheet');
});

test('Account dashboard links only currently editable attached Signups to participant editing', function () {
    $account = Account::factory()->create();
    $openSheet = Sheet::factory()->create([
        'title' => 'Editable Signup Sheet',
        'state' => Sheet::STATE_PUBLISHED,
        'selection_maximum' => 1,
    ]);
    $editableSignup = $openSheet->signups()->create([
        'name_snapshot' => 'Editable Participant',
        'email_snapshot' => $account->email,
    ]);
    $editableSignup->forceFill(['account_id' => $account->id])->save();

    $closedSheet = Sheet::factory()->create([
        'title' => 'Closed Signup Sheet',
        'state' => Sheet::STATE_PUBLISHED,
        'deadline_at' => now()->subMinute(),
        'selection_maximum' => 1,
    ]);
    $closedSignup = $closedSheet->signups()->create([
        'name_snapshot' => 'Closed Participant',
        'email_snapshot' => $account->email,
    ]);
    $closedSignup->forceFill(['account_id' => $account->id])->save();

    $this->actingAs($account)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Editable Signup Sheet')
        ->assertSee('Closed Signup Sheet')
        ->assertSeeHtml('href="'.route('signups.edit', $editableSignup, absolute: false).'"')
        ->assertDontSeeHtml('href="'.route('signups.edit', $closedSignup, absolute: false).'"');
});
