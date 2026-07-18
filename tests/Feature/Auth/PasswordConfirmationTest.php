<?php

use App\Models\Account;

test('confirm password screen can be rendered', function () {
    $account = Account::factory()->create();

    $response = $this->actingAs($account)->get(route('password.confirm'));

    $response->assertOk();
});
