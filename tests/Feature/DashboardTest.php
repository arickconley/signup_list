<?php

use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Database\Eloquent\Model;

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
