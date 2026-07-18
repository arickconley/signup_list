<?php

use App\Models\Account;

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
