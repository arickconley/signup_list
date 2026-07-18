<?php

use App\Enums\PasswordCredentialChange;
use App\Models\Account;
use App\Notifications\AccountPasswordChanged;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Livewire\Livewire;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
    Features::passkeys([
        'confirmPassword' => true,
    ]);
});

test('security settings page can be rendered', function () {
    $account = Account::factory()->create();

    $response = $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'));

    $response->assertOk();

    $response->assertSee('Replace password');
    $response->assertSee('Remove password');
    $response->assertSee('Passkeys');
    $response->assertSee('No passkeys yet');
    $response->assertSee('Two-factor authentication');
    $response->assertSee('Enable two-factor authentication');
});

test('security settings page requires password confirmation when enabled', function () {
    $account = Account::factory()->create();

    $response = $this->actingAs($account)
        ->get(route('security.edit'));

    $response->assertRedirect(route('password.confirm'));
});

test('guests cannot access security settings', function () {
    $this->get(route('security.edit'))->assertRedirect(route('login'));
});

test('unverified Accounts cannot access security settings', function () {
    $account = Account::factory()->unverified()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertRedirect(route('verification.notice'));
});

test('security settings page renders without two factor when feature is disabled', function () {
    config(['fortify.features' => []]);

    $account = Account::factory()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Replace password')
        ->assertDontSee('Manage your passkeys for passwordless sign-in')
        ->assertDontSee('Add a passkey to sign in without a password')
        ->assertDontSee('Two-factor authentication');
});

test('two factor authentication disabled when confirmation abandoned between requests', function () {
    $account = Account::factory()->create();

    $account->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($account);

    $component = Livewire::test('pages::settings.security');

    $component->assertSet('twoFactorEnabled', false);

    $this->assertDatabaseHas('users', [
        'id' => $account->id,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
    ]);
});

test('a passwordless Account can add a password after fresh authentication', function () {
    Notification::fake();
    $account = Account::factory()->passwordless()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    $response = Livewire::test('pages::settings.security')
        ->set('password', 'A-secure-password-2048!')
        ->set('password_confirmation', 'A-secure-password-2048!')
        ->call('setPassword');

    $response->assertHasNoErrors();

    expect(Hash::check('A-secure-password-2048!', $account->refresh()->password))->toBeTrue();
    Notification::assertSentTo(
        $account,
        AccountPasswordChanged::class,
        fn (AccountPasswordChanged $notification): bool => $notification->change === PasswordCredentialChange::Added,
    );
});

test('an Account can replace its password after fresh authentication', function () {
    Notification::fake();
    $account = Account::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    $response = Livewire::test('pages::settings.security')
        ->set('password', 'A-replacement-password-2048!')
        ->set('password_confirmation', 'A-replacement-password-2048!')
        ->call('setPassword');

    $response
        ->assertHasNoErrors()
        ->assertSee('Password replaced.');

    expect(Hash::check('A-replacement-password-2048!', $account->refresh()->password))->toBeTrue();
    Notification::assertSentTo(
        $account,
        AccountPasswordChanged::class,
        fn (AccountPasswordChanged $notification): bool => $notification->change === PasswordCredentialChange::Replaced,
    );
});

test('an Account can remove its password after fresh authentication', function () {
    Notification::fake();
    $account = Account::factory()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.security')
        ->call('removePassword')
        ->assertHasNoErrors()
        ->assertSee('Password removed.');

    expect($account->refresh()->password)->toBeNull();
    Notification::assertSentTo(
        $account,
        AccountPasswordChanged::class,
        fn (AccountPasswordChanged $notification): bool => $notification->change === PasswordCredentialChange::Removed,
    );
});

test('an Account cannot add a password without fresh authentication', function () {
    Notification::fake();
    $account = Account::factory()->passwordless()->create();

    $this->actingAs($account);

    Livewire::test('pages::settings.security')
        ->set('password', 'A-secure-password-2048!')
        ->set('password_confirmation', 'A-secure-password-2048!')
        ->call('setPassword')
        ->assertStatus(423);

    expect($account->refresh()->password)->toBeNull();
    Notification::assertNothingSent();
});

test('an Account cannot remove its password without fresh authentication', function () {
    Notification::fake();
    $account = Account::factory()->create();

    $this->actingAs($account);

    Livewire::test('pages::settings.security')
        ->call('removePassword')
        ->assertStatus(423);

    expect(Hash::check('password', $account->refresh()->password))->toBeTrue();
    Notification::assertNothingSent();
});

test('adding a password enforces the application password rules', function () {
    Notification::fake();
    $account = Account::factory()->passwordless()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.security')
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('setPassword')
        ->assertHasErrors('password');

    expect($account->refresh()->password)->toBeNull();
    Notification::assertNothingSent();
});
