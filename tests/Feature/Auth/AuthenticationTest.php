<?php

use App\Models\Account;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('accounts can authenticate using the login screen', function () {
    $account = Account::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $account->email,
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('accounts can not authenticate with invalid password', function () {
    $account = Account::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $account->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrorsIn('email');

    $this->assertGuest();
});

test('accounts with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $account = Account::factory()->withTwoFactor()->create();

    $response = $this->post(route('login.store'), [
        'email' => $account->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

test('accounts can logout', function () {
    $account = Account::factory()->create();

    $response = $this->actingAs($account)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});
