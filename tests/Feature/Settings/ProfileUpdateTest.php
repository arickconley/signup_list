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

test('profile form detects an initial browser timezone', function () {
    $account = Account::factory()->create(['timezone' => null]);

    $this->actingAs($account)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('Intl.DateTimeFormat().resolvedOptions().timeZone', false)
        ->assertSee('$wire.setDetectedTimezone', false);
});

test('newly verified Account completes onboarding through profile settings', function () {
    $account = Account::factory()->passwordless()->create([
        'name' => null,
        'timezone' => null,
    ]);

    $this->actingAs($account);

    Livewire::test('pages::settings.profile')
        ->assertSee('Complete your profile')
        ->set('name', 'New Account')
        ->set('phone', '555-0110')
        ->set('timezone', 'America/Denver')
        ->call('updateProfileInformation')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    expect($account->refresh()->hasCompleteProfile())->toBeTrue();
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

test('profile defaults can be updated with normalized values', function () {
    $account = Account::factory()->create();

    $this->actingAs($account);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Test Account')
        ->set('email', '  TEST@Example.COM ')
        ->set('phone', '555-0100')
        ->set('timezone', 'America/Chicago')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($account->refresh())
        ->name->toBe('Test Account')
        ->email->toBe('test@example.com')
        ->phone->toBe('555-0100')
        ->timezone->toBe('America/Chicago');
});

test('profile form rejects an invalid timezone with an associated error', function () {
    $account = Account::factory()->create();

    $this->actingAs($account);

    Livewire::test('pages::settings.profile')
        ->set('timezone', 'Mars/Olympus_Mons')
        ->call('updateProfileInformation')
        ->assertHasErrors(['timezone'])
        ->assertSee('Please correct the highlighted fields.')
        ->assertSeeHtml('aria-describedby="timezone-error"')
        ->assertSeeHtml('id="timezone-error"');

    expect($account->refresh()->timezone)->toBe('America/Los_Angeles');
});

test('detected browser timezone initializes a blank profile and can be overridden', function () {
    $account = Account::factory()->create(['timezone' => null]);

    $this->actingAs($account);

    Livewire::test('pages::settings.profile')
        ->assertSet('timezone', '')
        ->call('setDetectedTimezone', 'America/New_York')
        ->assertSet('timezone', 'America/New_York')
        ->set('timezone', 'Europe/Paris')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($account->refresh()->timezone)->toBe('Europe/Paris');
});

test('captured Account defaults are isolated from later profile updates', function () {
    $account = Account::factory()->create([
        'name' => 'Earlier Name',
        'email' => 'earlier@example.com',
        'phone' => '555-0101',
        'timezone' => 'America/Los_Angeles',
    ]);
    $historicalSnapshot = $account->accountDefaults();

    $this->actingAs($account);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Future Name')
        ->set('phone', '555-0199')
        ->set('timezone', 'America/New_York')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $futureDefaults = $account->refresh()->accountDefaults();

    expect($historicalSnapshot)
        ->name->toBe('Earlier Name')
        ->email->toBe('earlier@example.com')
        ->phone->toBe('555-0101')
        ->timezone->toBe('America/Los_Angeles')
        ->and($futureDefaults)
        ->name->toBe('Future Name')
        ->email->toBe('earlier@example.com')
        ->phone->toBe('555-0199')
        ->timezone->toBe('America/New_York');
});

test('blank optional phone is stored as null', function () {
    $account = Account::factory()->create(['phone' => '555-0101']);

    $this->actingAs($account);

    Livewire::test('pages::settings.profile')
        ->set('phone', '   ')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($account->refresh()->phone)->toBeNull();
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
