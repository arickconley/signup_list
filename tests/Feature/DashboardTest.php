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

test('Account dashboard lists only attached non-archived joined Signup Sheets and current selections', function () {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();

    $joinedSheet = Sheet::factory()->create([
        'title' => 'Joined Garden Day',
        'state' => Sheet::STATE_PUBLISHED,
    ]);
    $joinedOption = $joinedSheet->options()->create([
        'name' => 'Welcome Table',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $joinedSignup = $joinedSheet->signups()->create([
        'name_snapshot' => 'Dashboard Participant',
        'email_snapshot' => $account->email,
    ]);
    $joinedSignup->forceFill(['account_id' => $account->id])->save();
    $joinedSignup->optionClaims()->create(['option_id' => $joinedOption->id]);

    $olderJoinedSheet = Sheet::factory()->create([
        'title' => 'Older Joined Sheet',
        'state' => Sheet::STATE_PUBLISHED,
    ]);
    $olderJoinedSignup = $olderJoinedSheet->signups()->create([
        'name_snapshot' => 'Older Dashboard Participant',
    ]);
    $olderJoinedSignup->forceFill([
        'account_id' => $account->id,
        'created_at' => now()->subDay(),
    ])->save();

    $pendingSheet = Sheet::factory()->create(['title' => 'Pending Private Sheet']);
    $pendingSignup = $pendingSheet->signups()->create([
        'name_snapshot' => 'Pending Participant',
        'email_snapshot' => 'pending-dashboard@example.com',
    ]);
    $pendingSignup->pendingAccountAssociation()->create(['account_id' => $account->id]);

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
        ->assertSee('Joined Sheets')
        ->assertSee('Joined Garden Day')
        ->assertSee('Older Joined Sheet')
        ->assertSeeInOrder(['Joined Garden Day', 'Older Joined Sheet'])
        ->assertSee('Dashboard Participant')
        ->assertSee('Welcome Table')
        ->assertSeeHtml('href="'.route('sheets.show', $joinedSheet, absolute: false).'"')
        ->assertDontSee('Pending Private Sheet')
        ->assertDontSee('Archived Private Sheet')
        ->assertDontSee('Other Account Sheet');
});
