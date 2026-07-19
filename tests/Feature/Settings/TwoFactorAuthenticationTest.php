<?php

use App\Enums\TwoFactorAuthenticationChange;
use App\Models\Account;
use App\Notifications\AccountTwoFactorAuthenticationChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
});

test('a passwordless Account cannot enable two-factor authentication', function () {
    $account = Account::factory()->passwordless()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.two-factor-setup-modal', [
        'requiresConfirmation' => true,
    ])
        ->call('startTwoFactorSetup')
        ->assertForbidden();

    expect($account->refresh()->two_factor_secret)->toBeNull();
});

test('a passwordless Account is directed to add a password before two-factor authentication setup', function () {
    $account = Account::factory()->passwordless()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSeeText('Add password')
        ->assertDontSeeText('Enable two-factor authentication');
});

test('the two-factor management endpoint rejects a passwordless Account', function () {
    $account = Account::factory()->passwordless()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'))
        ->assertForbidden();

    expect($account->refresh()->two_factor_secret)->toBeNull();
});

test('direct Fortify management endpoints cannot bypass the audited two-factor authentication interface', function () {
    $account = Account::factory()->withTwoFactor()->create();
    $storedRecoveryCodes = $account->two_factor_recovery_codes;

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('two-factor.recovery-codes'))->assertNotFound();
    $this->post(route('two-factor.regenerate-recovery-codes'))->assertNotFound();
    $this->delete(route('two-factor.disable'))->assertNotFound();

    expect($account->refresh()->two_factor_recovery_codes)->toBe($storedRecoveryCodes)
        ->and($account->hasEnabledTwoFactorAuthentication())->toBeTrue();

    $account->forceFill([
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->post(route('two-factor.enable'))->assertNotFound();
    $this->post(route('two-factor.confirm'), ['code' => '000000'])->assertNotFound();
    $this->get(route('two-factor.qr-code'))->assertNotFound();
    $this->get(route('two-factor.secret-key'))->assertNotFound();

    expect($account->refresh()->two_factor_secret)->toBeNull();
});

test('two-factor setup requires fresh authentication', function () {
    $account = Account::factory()->create();

    $this->actingAs($account);

    Livewire::test('pages::settings.two-factor-setup-modal', [
        'requiresConfirmation' => true,
    ])
        ->call('startTwoFactorSetup')
        ->assertStatus(423);

    expect($account->refresh()->two_factor_secret)->toBeNull();
});

test('an Account confirms two-factor authentication setup and sees encrypted recovery codes once', function () {
    $account = Account::factory()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test('pages::settings.two-factor-setup-modal', [
        'requiresConfirmation' => true,
    ])->call('startTwoFactorSetup');

    $account->refresh();
    $secret = decrypt($account->two_factor_secret);
    $code = (new Google2FA)->getCurrentOtp($secret);

    $component
        ->set('code', $code)
        ->call('confirmTwoFactor')
        ->assertHasNoErrors()
        ->assertSet('setupComplete', true)
        ->assertCount('recoveryCodes', 8);

    $account->refresh();

    expect($account->two_factor_confirmed_at)->not->toBeNull()
        ->and($account->two_factor_secret)->not->toBe($secret)
        ->and(json_decode(decrypt($account->two_factor_recovery_codes), true))->toHaveCount(8);

    Livewire::test('pages::settings.two-factor.recovery-codes', [
        'requiresConfirmation' => true,
    ])->assertSet('recoveryCodes', []);
});

test('manual Two-Factor Authentication setup controls are labeled and announce copy state', function () {
    $account = Account::factory()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.two-factor-setup-modal', [
        'requiresConfirmation' => true,
    ])
        ->call('startTwoFactorSetup')
        ->assertSeeHtml('<label for="two-factor-manual-key"')
        ->assertSeeHtml('aria-label="Copy manual setup key"')
        ->assertSeeHtml('role="status"')
        ->assertSeeHtml('aria-live="polite"')
        ->assertSeeText('Manual setup key copied.');
});

test('an enabled Account cannot reconfirm to redisclose recovery codes', function () {
    Notification::fake();
    $account = Account::factory()->withTwoFactor()->create();
    $secret = decrypt($account->two_factor_secret);

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.two-factor-setup-modal', [
        'requiresConfirmation' => true,
    ])
        ->set('code', (new Google2FA)->getCurrentOtp($secret))
        ->call('confirmTwoFactor')
        ->assertHasErrors('code')
        ->assertSet('recoveryCodes', []);

    Notification::assertNothingSent();
});

test('two-factor authentication setup rejects an invalid confirmation code', function () {
    Notification::fake();
    $account = Account::factory()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.two-factor-setup-modal', [
        'requiresConfirmation' => true,
    ])
        ->call('startTwoFactorSetup')
        ->set('code', '000000')
        ->call('confirmTwoFactor')
        ->assertHasErrors('code');

    expect($account->refresh()->two_factor_confirmed_at)->toBeNull();
    Notification::assertNothingSent();
});

test('confirmed two-factor setup sends a queued security notification after commit', function () {
    Notification::fake();
    $account = Account::factory()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test('pages::settings.two-factor-setup-modal', [
        'requiresConfirmation' => true,
    ])->call('startTwoFactorSetup');

    $secret = decrypt($account->refresh()->two_factor_secret);

    $component
        ->set('code', (new Google2FA)->getCurrentOtp($secret))
        ->call('confirmTwoFactor')
        ->assertHasNoErrors();

    Notification::assertSentTo(
        $account,
        AccountTwoFactorAuthenticationChanged::class,
        fn (AccountTwoFactorAuthenticationChanged $notification): bool => $notification->change === TwoFactorAuthenticationChange::Enabled
            && $notification instanceof ShouldQueue
            && $notification instanceof ShouldQueueAfterCommit,
    );
});

test('two-factor confirmation rejects authentication that became stale during setup', function () {
    Notification::fake();
    $account = Account::factory()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test('pages::settings.two-factor-setup-modal', [
        'requiresConfirmation' => true,
    ])->call('startTwoFactorSetup');

    $secret = decrypt($account->refresh()->two_factor_secret);
    session()->forget('auth.password_confirmed_at');

    $component
        ->set('code', (new Google2FA)->getCurrentOtp($secret))
        ->call('confirmTwoFactor')
        ->assertStatus(423);

    expect($account->refresh()->two_factor_confirmed_at)->toBeNull();
    Notification::assertNothingSent();
});

test('canceling unconfirmed two-factor authentication setup removes its credentials', function () {
    Notification::fake();
    $account = Account::factory()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.two-factor-setup-modal', [
        'requiresConfirmation' => true,
    ])
        ->call('startTwoFactorSetup')
        ->call('closeModal');

    $account->refresh();

    expect($account->two_factor_secret)->toBeNull()
        ->and($account->two_factor_recovery_codes)->toBeNull()
        ->and($account->two_factor_confirmed_at)->toBeNull();
    Notification::assertNothingSent();
});

test('an Account disables two-factor authentication after fresh authentication', function () {
    Notification::fake();
    $account = Account::factory()->withTwoFactor()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.security')
        ->assertSeeHtml('wire:confirm="Disable Two-Factor Authentication? Password sign-in will no longer require an authenticator code."')
        ->call('disable')
        ->assertHasNoErrors()
        ->assertSet('twoFactorEnabled', false);

    $account->refresh();

    expect($account->two_factor_secret)->toBeNull()
        ->and($account->two_factor_recovery_codes)->toBeNull()
        ->and($account->two_factor_confirmed_at)->toBeNull();

    Notification::assertSentTo(
        $account,
        AccountTwoFactorAuthenticationChanged::class,
        fn (AccountTwoFactorAuthenticationChanged $notification): bool => $notification->change === TwoFactorAuthenticationChange::Disabled,
    );
});

test('disabling two-factor authentication rejects stale authentication', function () {
    Notification::fake();
    $account = Account::factory()->withTwoFactor()->create();

    $this->actingAs($account);

    Livewire::test('pages::settings.security')
        ->call('disable')
        ->assertStatus(423);

    expect($account->refresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
    Notification::assertNothingSent();
});

test('an Account regenerates recovery codes and sees the replacement set once', function () {
    Notification::fake();
    $account = Account::factory()->withTwoFactor()->create();
    $storedBefore = $account->two_factor_recovery_codes;

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.two-factor.recovery-codes', [
        'requiresConfirmation' => true,
    ])
        ->assertSet('recoveryCodes', [])
        ->assertSeeText('Regenerate recovery codes')
        ->call('regenerateRecoveryCodes')
        ->assertHasNoErrors()
        ->assertCount('recoveryCodes', 8)
        ->assertSeeText('Save these recovery codes now')
        ->assertDontSee('recovery-code-1');

    $account->refresh();

    expect($account->two_factor_recovery_codes)->not->toBe($storedBefore)
        ->and(json_decode(decrypt($account->two_factor_recovery_codes), true))->toHaveCount(8);

    Notification::assertSentTo(
        $account,
        AccountTwoFactorAuthenticationChanged::class,
        fn (AccountTwoFactorAuthenticationChanged $notification): bool => $notification->change === TwoFactorAuthenticationChange::RecoveryCodesRegenerated,
    );

    Livewire::test('pages::settings.two-factor.recovery-codes', [
        'requiresConfirmation' => true,
    ])->assertSet('recoveryCodes', []);
});

test('regenerating recovery codes rejects stale authentication', function () {
    Notification::fake();
    $account = Account::factory()->withTwoFactor()->create();
    $storedBefore = $account->two_factor_recovery_codes;

    $this->actingAs($account);

    Livewire::test('pages::settings.two-factor.recovery-codes', [
        'requiresConfirmation' => true,
    ])
        ->call('regenerateRecoveryCodes')
        ->assertStatus(423);

    expect($account->refresh()->two_factor_recovery_codes)->toBe($storedBefore);
    Notification::assertNothingSent();
});

test('password login completes with a valid two-factor authentication code', function () {
    $googleTwoFactor = new Google2FA;
    $secret = $googleTwoFactor->generateSecretKey();
    $account = Account::factory()->create([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->post(route('login.store'), [
        'email' => $account->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login.store'), [
        'code' => $googleTwoFactor->getCurrentOtp($secret),
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($account);
});

test('password login rejects an invalid two-factor authentication code', function () {
    $account = Account::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'email' => $account->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login.store'), [
        'code' => '000000',
    ])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

test('a recovery code signs in once and is then consumed', function () {
    $recoveryCode = 'recover-account-once';
    $account = Account::factory()->withTwoFactor()->create([
        'two_factor_recovery_codes' => encrypt(json_encode([$recoveryCode])),
    ]);

    $this->post(route('login.store'), [
        'email' => $account->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login.store'), [
        'recovery_code' => $recoveryCode,
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($account);
    expect($account->refresh()->recoveryCodes())->not->toContain($recoveryCode);

    $this->post(route('logout'));
    $this->post(route('login.store'), [
        'email' => $account->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login.store'), [
        'recovery_code' => $recoveryCode,
    ])->assertSessionHasErrors('recovery_code');

    $this->assertGuest();
});

test('removing the password also disables its two-factor authentication challenge', function () {
    Notification::fake();
    $account = Account::factory()->withTwoFactor()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.security')
        ->call('removePassword')
        ->assertHasNoErrors();

    $account->refresh();

    expect($account->password)->toBeNull()
        ->and($account->hasEnabledTwoFactorAuthentication())->toBeFalse();

    Notification::assertSentTo(
        $account,
        AccountTwoFactorAuthenticationChanged::class,
        fn (AccountTwoFactorAuthenticationChanged $notification): bool => $notification->change === TwoFactorAuthenticationChange::Disabled,
    );
});
