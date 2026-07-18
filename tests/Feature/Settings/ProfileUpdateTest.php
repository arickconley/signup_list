<?php

use App\Models\Account;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs(Account::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('passwordless Accounts without profile details can visit profile settings', function () {
    $account = Account::factory()->passwordless()->create(['name' => null]);

    $this->actingAs($account)
        ->get('/settings/profile')
        ->assertOk();
});

test('profile information can be updated', function () {
    $account = Account::factory()->create();

    $this->actingAs($account);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $account->refresh();

    expect($account->name)->toEqual('Test User');
    expect($account->email)->toEqual('test@example.com');
    expect($account->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when email address is unchanged', function () {
    $account = Account::factory()->create();

    $this->actingAs($account);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', $account->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($account->refresh()->email_verified_at)->not->toBeNull();
});

test('account can be deleted', function () {
    $account = Account::factory()->create();

    $this->actingAs($account);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($account->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('correct password must be provided to delete account', function () {
    $account = Account::factory()->create();

    $this->actingAs($account);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($account->fresh())->not->toBeNull();
});
