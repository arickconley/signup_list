<?php

use App\Models\Account;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
});

test('two factor challenge redirects to login when not authenticated', function () {
    $response = $this->get(route('two-factor.login'));

    $response->assertRedirect(route('login'));
});

test('two factor challenge can be rendered', function () {
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $account = Account::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'email' => $account->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $response = $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertSeeHtml('<label for="recovery_code"')
        ->assertSeeHtml('type="button"')
        ->assertSeeHtml('x-on:click="toggleInput()"');

    expect(substr_count($response->getContent(), 'x-on:click="toggleInput()"'))->toBe(2);
});
