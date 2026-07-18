<?php

use App\Models\Account;
use Livewire\Livewire;
use Tests\Support\VirtualPasskey;
use Tests\TestCase;

function registerVirtualPasskeyForAuthenticatedAccount(
    TestCase $test,
    VirtualPasskey $passkey,
    string $name,
): int {
    $options = $test->getJson(route('passkey.registration-options'))
        ->assertOk()
        ->assertJsonPath('options.rp.id', 'localhost')
        ->json('options');

    $response = $test->postJson(route('passkey.store'), [
        'name' => $name,
        'credential' => $passkey->registrationCredential($options['challenge'], config('app.url')),
    ])->assertOk()
        ->assertJsonPath('status', 'passkey-registered')
        ->assertJsonPath('name', $name);

    return (int) $response->json('id');
}

test('passkey sign-in keeps passwordless email access available', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSeeText('Sign in with a passkey')
        ->assertSeeText('Email me a sign-in code');
});

test('an unverified Account cannot begin passkey registration', function () {
    $account = Account::factory()->unverified()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get(route('passkey.registration-options'))
        ->assertRedirect(route('verification.notice'));
});

test('passkey management API requires fresh authentication', function () {
    $account = Account::factory()->create();
    $passkey = new VirtualPasskey;

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $passkeyId = registerVirtualPasskeyForAuthenticatedAccount($this, $passkey, 'Personal Mac');
    session()->forget('auth.password_confirmed_at');

    $this->get(route('passkey.registration-options'))
        ->assertRedirect(route('password.confirm'));
    $this->post(route('passkey.store'))
        ->assertRedirect(route('password.confirm'));
    $this->delete(route('passkey.destroy', $passkeyId))
        ->assertRedirect(route('password.confirm'));
});

test('a verified Account registers and names multiple Passkeys after fresh authentication', function () {
    $account = Account::factory()->create();
    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    foreach (['Personal Mac', 'Travel key'] as $name) {
        $passkey = new VirtualPasskey;
        registerVirtualPasskeyForAuthenticatedAccount($this, $passkey, $name);
    }

    $this->get(route('security.edit'))
        ->assertOk()
        ->assertSeeText('Personal Mac')
        ->assertSeeText('Travel key');
});

test('a Passkey registration challenge is single-use', function () {
    $account = Account::factory()->create();
    $passkey = new VirtualPasskey;

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    $options = $this->getJson(route('passkey.registration-options'))->json('options');
    $payload = [
        'name' => 'Personal Mac',
        'credential' => $passkey->registrationCredential(
            $options['challenge'],
            config('app.url'),
        ),
    ];

    $this->postJson(route('passkey.store'), $payload)->assertOk();
    $this->postJson(route('passkey.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('credential');

    $this->get(route('security.edit'))
        ->assertOk()
        ->assertSeeText('Personal Mac');
});

test('a registered Passkey establishes an Account session', function () {
    $account = Account::factory()->create();
    $passkey = new VirtualPasskey;

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    registerVirtualPasskeyForAuthenticatedAccount($this, $passkey, 'Personal Mac');

    $this->post(route('logout'));
    $this->assertGuest();

    $options = $this->getJson(route('passkey.login-options'))
        ->assertOk()
        ->json('options');

    $this->postJson(route('passkey.login'), [
        'credential' => $passkey->authenticationCredential(
            $options['challenge'],
            $account->getPasskeyUserHandle(),
            origin: config('app.url'),
        ),
    ])->assertOk()
        ->assertJsonPath('redirect', route('dashboard'));

    $this->assertAuthenticatedAs($account);
});

test('Passkey authentication rejects a mismatched ceremony value', function (string $mismatch) {
    $account = Account::factory()->create();
    $passkey = new VirtualPasskey;

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    registerVirtualPasskeyForAuthenticatedAccount($this, $passkey, 'Personal Mac');
    $this->post(route('logout'));

    $options = $this->getJson(route('passkey.login-options'))
        ->assertOk()
        ->json('options');
    $challenge = $mismatch === 'challenge' ? 'mismatched-challenge' : $options['challenge'];
    $origin = $mismatch === 'origin' ? 'https://attacker.example' : config('app.url');
    $relyingPartyId = $mismatch === 'relying party' ? 'attacker.example' : 'localhost';

    $this->postJson(route('passkey.login'), [
        'credential' => $passkey->authenticationCredential(
            $challenge,
            $account->getPasskeyUserHandle(),
            origin: $origin,
            relyingPartyId: $relyingPartyId,
        ),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('credential');
    $this->assertGuest();
})->with(['challenge', 'origin', 'relying party']);

test('a replayed Passkey usage counter cannot authenticate', function () {
    $account = Account::factory()->create();
    $passkey = new VirtualPasskey;

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    registerVirtualPasskeyForAuthenticatedAccount($this, $passkey, 'Personal Mac');
    $this->post(route('logout'));

    $options = $this->getJson(route('passkey.login-options'))->json('options');
    $credential = $passkey->authenticationCredential(
        $options['challenge'],
        $account->getPasskeyUserHandle(),
        counter: 1,
        origin: config('app.url'),
    );

    $this->postJson(route('passkey.login'), ['credential' => $credential])->assertOk();
    $this->post(route('logout'));

    $options = $this->getJson(route('passkey.login-options'))->json('options');
    $replayedCounter = $passkey->authenticationCredential(
        $options['challenge'],
        $account->getPasskeyUserHandle(),
        counter: 1,
        origin: config('app.url'),
    );

    $this->postJson(route('passkey.login'), ['credential' => $replayedCounter])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('credential');
    $this->assertGuest();
});

test('Passkey registration rejects a mismatched ceremony value', function (string $mismatch) {
    $account = Account::factory()->create();
    $passkey = new VirtualPasskey;

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    $options = $this->getJson(route('passkey.registration-options'))
        ->assertOk()
        ->json('options');

    $challenge = $mismatch === 'challenge' ? 'mismatched-challenge' : $options['challenge'];
    $origin = $mismatch === 'origin' ? 'https://attacker.example' : config('app.url');
    $relyingPartyId = $mismatch === 'relying party' ? 'attacker.example' : 'localhost';

    $this->postJson(route('passkey.store'), [
        'name' => 'Untrusted key',
        'credential' => $passkey->registrationCredential(
            $challenge,
            $origin,
            $relyingPartyId,
        ),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('credential');

    $this->get(route('security.edit'))
        ->assertOk()
        ->assertSeeText('No passkeys yet');
})->with(['challenge', 'origin', 'relying party']);

test('Passkey confirmation establishes the standard fresh authentication session', function () {
    $account = Account::factory()->create();
    $passkey = new VirtualPasskey;

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    registerVirtualPasskeyForAuthenticatedAccount($this, $passkey, 'Personal Mac');
    session()->forget('auth.password_confirmed_at');

    $options = $this->getJson(route('passkey.confirm-options'))
        ->assertOk()
        ->json('options');

    $this->postJson(route('passkey.confirm'), [
        'credential' => $passkey->authenticationCredential(
            $options['challenge'],
            $account->getPasskeyUserHandle(),
            origin: config('app.url'),
        ),
    ])->assertOk()
        ->assertSessionHas('auth.password_confirmed_at', now()->timestamp);

    $this->get(route('security.edit'))->assertOk();
});

test('an Account cannot confirm with another Account credential', function () {
    $owner = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $passkey = new VirtualPasskey;

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    registerVirtualPasskeyForAuthenticatedAccount($this, $passkey, 'Owner key');
    $this->post(route('logout'));

    $this->actingAs($otherAccount);
    $options = $this->getJson(route('passkey.confirm-options'))
        ->assertOk()
        ->json('options');

    $this->postJson(route('passkey.confirm'), [
        'credential' => $passkey->authenticationCredential(
            $options['challenge'],
            $owner->getPasskeyUserHandle(),
            origin: config('app.url'),
        ),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('credential')
        ->assertSessionMissing('auth.password_confirmed_at');

    $this->assertAuthenticatedAs($otherAccount);
});

test('an Account views and revokes individual Passkeys', function () {
    $account = Account::factory()->create();
    $revokedPasskey = new VirtualPasskey;
    $remainingPasskey = new VirtualPasskey;

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $revokedId = registerVirtualPasskeyForAuthenticatedAccount($this, $revokedPasskey, 'Personal Mac');
    registerVirtualPasskeyForAuthenticatedAccount($this, $remainingPasskey, 'Travel key');

    Livewire::test('pages::settings.security')
        ->assertSeeText('Personal Mac')
        ->assertSeeText('Travel key')
        ->call('confirmDelete', $revokedId)
        ->assertSet('deletingPasskeyName', 'Personal Mac')
        ->call('deletePasskey')
        ->assertDontSeeText('Personal Mac')
        ->assertSeeText('Travel key');

    $this->post(route('logout'));

    $options = $this->getJson(route('passkey.login-options'))->json('options');
    $this->postJson(route('passkey.login'), [
        'credential' => $revokedPasskey->authenticationCredential(
            $options['challenge'],
            $account->getPasskeyUserHandle(),
            origin: config('app.url'),
        ),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('credential');
    $this->assertGuest();

    $options = $this->getJson(route('passkey.login-options'))->json('options');
    $this->postJson(route('passkey.login'), [
        'credential' => $remainingPasskey->authenticationCredential(
            $options['challenge'],
            $account->getPasskeyUserHandle(),
            origin: config('app.url'),
        ),
    ])->assertOk();
    $this->assertAuthenticatedAs($account);
});

test('an expired fresh authentication session cannot revoke through the Livewire action', function () {
    $account = Account::factory()->create();
    $passkey = new VirtualPasskey;

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $passkeyId = registerVirtualPasskeyForAuthenticatedAccount($this, $passkey, 'Personal Mac');
    session()->put('auth.password_confirmed_at', 0);

    Livewire::test('pages::settings.security')
        ->call('confirmDelete', $passkeyId)
        ->call('deletePasskey')
        ->assertStatus(423);

    $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSeeText('Personal Mac');
});

test('an Account cannot revoke another Account Passkey', function () {
    $owner = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $passkey = new VirtualPasskey;

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $passkeyId = registerVirtualPasskeyForAuthenticatedAccount($this, $passkey, 'Owner key');
    $this->post(route('logout'));

    $this->actingAs($otherAccount)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->deleteJson(route('passkey.destroy', $passkeyId))
        ->assertForbidden();

    $this->post(route('logout'));
    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSeeText('Owner key');
});
