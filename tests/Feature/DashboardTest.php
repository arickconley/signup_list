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
