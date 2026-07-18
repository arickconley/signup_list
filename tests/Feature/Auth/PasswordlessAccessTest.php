<?php

use App\Actions\ChangeAccountPassword;
use App\Mail\AccountAccessMail;
use App\Models\Account;
use App\Models\AccountAccessChallenge;
use App\Models\PendingAccountAssociation;
use App\Models\Sheet;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

function renderedAccountAccessMail(): string
{
    Mail::assertQueued(AccountAccessMail::class);

    /** @var AccountAccessMail $mail */
    $mail = Mail::queued(AccountAccessMail::class)->last();

    return $mail->render();
}

function magicLinkFromAccountAccessMail(): string
{
    preg_match('/href="([^"]*\/access\/[^"]*)"/', renderedAccountAccessMail(), $matches);

    return html_entity_decode($matches[1] ?? '');
}

function codeFromAccountAccessMail(): string
{
    preg_match('/\b(\d{6})\b/', strip_tags(renderedAccountAccessMail()), $matches);

    return $matches[1] ?? '';
}

test('Account access screen keeps email sign-in alongside password sign-in', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Email me a sign-in code')
        ->assertSee('Sign in with password')
        ->assertSee('Forgot password?')
        ->assertSeeHtml('name="password"');
});

test('requesting access returns a neutral response', function () {
    Mail::fake();

    $this->post('/access', ['email' => 'alice@example.com'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'If the address can receive email, a sign-in code is on its way.');
});

test('requesting access does not reveal whether an Account exists', function () {
    Mail::fake();
    Account::factory()->create(['email' => 'existing@example.com']);

    foreach (['existing@example.com', 'new@example.com'] as $email) {
        $this->post('/access', ['email' => $email])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'If the address can receive email, a sign-in code is on its way.');
    }

    Mail::assertQueuedCount(2);
});

test('requesting access queues email to the normalized address', function () {
    Mail::fake();

    $this->post('/access', ['email' => '  Alice@Example.COM ']);

    Mail::assertQueued(AccountAccessMail::class, function (AccountAccessMail $mail): bool {
        return $mail->hasTo('alice@example.com');
    });
});

test('Account emails are normalized and globally unique', function () {
    $account = Account::factory()->create(['email' => '  Alice@Example.COM ']);

    expect($account->email)->toBe('alice@example.com')
        ->and(fn () => Account::factory()->create(['email' => 'alice@example.com']))
        ->toThrow(QueryException::class);
});

test('requesting access enforces the resend cooldown without changing the response', function () {
    Mail::fake();

    foreach (range(1, 2) as $_) {
        $this->post('/access', ['email' => 'alice@example.com'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'If the address can receive email, a sign-in code is on its way.');
    }

    Mail::assertQueuedTimes(AccountAccessMail::class, 1);
});

test('requesting access enforces the configured IP limit', function () {
    Mail::fake();
    config()->set('account-access.send_limit_per_email', 10);
    config()->set('account-access.send_limit_per_ip', 2);

    foreach (['alice@example.com', 'bob@example.com', 'carol@example.com'] as $email) {
        $this->post('/access', ['email' => $email])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'If the address can receive email, a sign-in code is on its way.');
    }

    Mail::assertQueuedTimes(AccountAccessMail::class, 2);
});

test('resending after the cooldown invalidates the prior credentials', function () {
    Mail::fake();

    $this->post('/access', ['email' => 'alice@example.com']);
    $oldMagicLink = magicLinkFromAccountAccessMail();

    $this->travel(61)->seconds();
    $this->post('/access', ['email' => 'alice@example.com']);
    $newCode = codeFromAccountAccessMail();

    Mail::assertQueuedTimes(AccountAccessMail::class, 2);
    $this->get($oldMagicLink)->assertSessionHasErrors('access');
    $this->post('/access/code', ['code' => $newCode])->assertRedirect(route('profile.edit'));
    $this->assertAuthenticated();
});

test('a magic link creates a verified passwordless Account and signs it in', function () {
    Mail::fake();

    $this->post('/access', ['email' => 'alice@example.com']);

    $this->get(magicLinkFromAccountAccessMail())->assertRedirect(route('profile.edit'));

    $this->assertAuthenticated();
    expect(auth()->user()->hasVerifiedEmail())->toBeTrue()
        ->and(auth()->user()->password)->toBeNull();
});

test('the emailed code creates a verified passwordless Account and signs it in', function () {
    Mail::fake();

    $this->post('/access', ['email' => 'alice@example.com']);

    $this->post('/access/code', ['code' => codeFromAccountAccessMail()])
        ->assertRedirect(route('profile.edit'));

    $this->assertAuthenticated();
    expect(auth()->user()->hasVerifiedEmail())->toBeTrue();
});

test('email verification establishes fresh authentication', function () {
    Mail::fake();

    $this->post('/access', ['email' => 'alice@example.com']);

    $this->post('/access/code', ['code' => codeFromAccountAccessMail()])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('auth.password_confirmed_at', now()->timestamp);
});

test('passwordless email sign-in remains available after adding a password', function () {
    Notification::fake();
    $account = Account::factory()->passwordless()->create();
    app(ChangeAccountPassword::class)->set($account, 'A-secure-password-2048!');
    Mail::fake();

    $this->post(route('account-access.request'), ['email' => $account->email]);
    $this->post(route('account-access.code'), ['code' => codeFromAccountAccessMail()])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($account);
    expect($account->refresh()->password)->not->toBeNull();
});

test('passwordless email sign-in bypasses the password two-factor challenge', function () {
    $account = Account::factory()->withTwoFactor()->create();
    Mail::fake();

    $this->post(route('account-access.request'), ['email' => $account->email]);
    $this->post(route('account-access.code'), ['code' => codeFromAccountAccessMail()])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($account);
});

test('passwordless email sign-in remains available after removing a password', function () {
    Notification::fake();
    $account = Account::factory()->create();
    app(ChangeAccountPassword::class)->remove($account);
    Mail::fake();

    $this->post(route('account-access.request'), ['email' => $account->email]);
    $this->post(route('account-access.code'), ['code' => codeFromAccountAccessMail()])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($account);
    expect($account->refresh()->password)->toBeNull();
});

test('an invalid code fails without creating a session', function () {
    Mail::fake();

    $this->post('/access', ['email' => 'alice@example.com']);

    $this->post('/access/code', ['code' => '999999'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('access');
    $this->assertGuest();
    expect(Account::query()->count())->toBe(0);
});

test('code entry enforces the configured address limit', function () {
    Mail::fake();
    config()->set('account-access.verification_limit_per_email', 2);
    config()->set('account-access.verification_limit_per_ip', 10);

    $this->post('/access', ['email' => 'alice@example.com']);
    $code = codeFromAccountAccessMail();
    $wrongCode = $code === '000000' ? '000001' : '000000';

    $this->post('/access/code', ['code' => $wrongCode])->assertSessionHasErrors('access');
    $this->post('/access/code', ['code' => $wrongCode])->assertSessionHasErrors('access');
    $this->post('/access/code', ['code' => $code])->assertSessionHasErrors('access');

    $this->assertGuest();
    expect(Account::query()->count())->toBe(0);
});

test('using a code invalidates its companion magic link', function () {
    Mail::fake();

    $this->post('/access', ['email' => 'alice@example.com']);
    $magicLink = magicLinkFromAccountAccessMail();
    $code = codeFromAccountAccessMail();

    $this->post('/access/code', ['code' => $code])->assertRedirect(route('profile.edit'));
    auth()->logout();

    $this->get($magicLink)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('access');
    $this->assertGuest();
    expect(Account::query()->count())->toBe(1);
});

test('verification reuses the normalized Account email', function () {
    Mail::fake();
    $account = Account::factory()->unverified()->create([
        'email' => 'alice@example.com',
    ]);

    $this->post('/access', ['email' => '  Alice@Example.COM ']);
    $this->post('/access/code', ['code' => codeFromAccountAccessMail()]);

    $this->assertAuthenticatedAs($account);
    expect($account->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and($account->fresh()->password)->not->toBeNull()
        ->and(Account::query()->count())->toBe(1);
});

test('passwordless code attaches a normalized-email Pending Account Association', function () {
    Mail::fake();
    $account = Account::factory()->unverified()->create([
        'email' => 'participant@example.com',
    ]);
    $sheet = Sheet::factory()->create();
    $option = $sheet->options()->create([
        'name' => 'Proof Selection',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Participant Snapshot',
        'email_snapshot' => 'participant@example.com',
        'phone_snapshot' => '555-0144',
        'name_consent' => true,
        'email_consent' => false,
        'phone_consent' => true,
    ]);
    $association = $signup->pendingAccountAssociation()->create([
        'account_id' => $account->id,
    ]);
    $claim = $signup->optionClaims()->create(['option_id' => $option->id]);
    $snapshots = $signup->only([
        'name_snapshot',
        'email_snapshot',
        'phone_snapshot',
        'name_consent',
        'email_consent',
        'phone_consent',
    ]);

    $this->post('/access', ['email' => '  PARTICIPANT@Example.COM  ']);
    $this->post('/access/code', ['code' => codeFromAccountAccessMail()])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($account);
    expect($signup->refresh()->account_id)->toBe($account->id)
        ->and($signup->only(array_keys($snapshots)))->toBe($snapshots)
        ->and($signup->optionClaims()->pluck('id')->all())->toBe([$claim->id])
        ->and($option->refresh()->claimed_count)->toBe(1)
        ->and(PendingAccountAssociation::query()->find($association->id))->toBeNull();
});

test('passwordless magic link attaches for an already-verified Account', function () {
    Mail::fake();
    $account = Account::factory()->create([
        'email' => 'verified@example.com',
    ]);
    $signup = Sheet::factory()->create()->signups()->create([
        'name_snapshot' => 'Verified Participant',
        'email_snapshot' => 'verified@example.com',
    ]);
    $association = $signup->pendingAccountAssociation()->create([
        'account_id' => $account->id,
    ]);

    $this->post('/access', ['email' => 'verified@example.com']);
    $this->get(magicLinkFromAccountAccessMail())
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($account);
    expect($signup->refresh()->account_id)->toBe($account->id)
        ->and(PendingAccountAssociation::query()->find($association->id))->toBeNull();
});

test('passwordless verification succeeds neutrally when Account and Signup Sheet conflict', function () {
    Mail::fake();
    $account = Account::factory()->create([
        'email' => 'conflict@example.com',
    ]);
    $sheet = Sheet::factory()->create();
    $existingOption = $sheet->options()->create([
        'name' => 'Private Existing Selection',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $candidateOption = $sheet->options()->create([
        'name' => 'Pending Selection',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 2,
    ]);
    $existingSignup = $sheet->signups()->create([
        'name_snapshot' => 'Private Existing Participant',
        'email_snapshot' => 'prior@example.com',
    ]);
    $existingSignup->forceFill(['account_id' => $account->id])->save();
    $existingClaim = $existingSignup->optionClaims()->create([
        'option_id' => $existingOption->id,
    ]);
    $candidateSignup = $sheet->signups()->create([
        'name_snapshot' => 'Pending Participant',
        'email_snapshot' => 'conflict@example.com',
    ]);
    $candidateAssociation = $candidateSignup->pendingAccountAssociation()->create([
        'account_id' => $account->id,
    ]);
    $candidateClaim = $candidateSignup->optionClaims()->create([
        'option_id' => $candidateOption->id,
    ]);

    $this->post('/access', ['email' => 'conflict@example.com']);
    $response = $this->post('/access/code', ['code' => codeFromAccountAccessMail()]);

    $response
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors()
        ->assertDontSee('Private Existing Participant')
        ->assertDontSee('Pending Participant')
        ->assertDontSee((string) $existingSignup->id)
        ->assertDontSee((string) $candidateSignup->id);
    $this->assertAuthenticatedAs($account);

    expect($existingSignup->refresh()->account_id)->toBe($account->id)
        ->and($candidateSignup->refresh()->account_id)->toBeNull()
        ->and(PendingAccountAssociation::query()->find($candidateAssociation->id))->not->toBeNull()
        ->and($existingSignup->optionClaims()->pluck('id')->all())->toBe([$existingClaim->id])
        ->and($candidateSignup->optionClaims()->pluck('id')->all())->toBe([$candidateClaim->id])
        ->and($existingOption->refresh()->claimed_count)->toBe(1)
        ->and($candidateOption->refresh()->claimed_count)->toBe(1);
});

test('access credentials persist only as hashes and queued delivery is encrypted after commit', function () {
    Mail::fake();

    $this->post('/access', ['email' => 'alice@example.com']);
    $rendered = renderedAccountAccessMail();
    preg_match('/\b(\d{6})\b/', strip_tags($rendered), $codeMatches);
    preg_match('/\/link\/([^?&"]+)/', $rendered, $tokenMatches);

    $challenge = AccountAccessChallenge::query()->sole();
    expect($challenge->code_hash)->not->toBe($codeMatches[1] ?? null)
        ->and(Hash::check($codeMatches[1] ?? '', $challenge->code_hash))->toBeTrue()
        ->and($challenge->token_hash)->not->toBe($tokenMatches[1] ?? null)
        ->and(Hash::check($tokenMatches[1] ?? '', $challenge->token_hash))->toBeTrue();

    /** @var AccountAccessMail $mail */
    $mail = Mail::queued(AccountAccessMail::class)->sole();
    expect($mail)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($mail)->toBeInstanceOf(ShouldQueueAfterCommit::class);
});

test('an expired magic link fails without creating a session', function () {
    Mail::fake();

    $this->post('/access', ['email' => 'alice@example.com']);
    $magicLink = magicLinkFromAccountAccessMail();

    $this->travel(16)->minutes();

    $this->get($magicLink)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('access');
    $this->assertGuest();
    expect(Account::query()->count())->toBe(0);
});

test('an expired code fails without creating a session', function () {
    Mail::fake();

    $this->post('/access', ['email' => 'alice@example.com']);
    $code = codeFromAccountAccessMail();

    $this->travel(16)->minutes();

    $this->post('/access/code', ['code' => $code])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('access');
    $this->assertGuest();
    expect(Account::query()->count())->toBe(0);
});

test('a magic link can be used only once', function () {
    Mail::fake();

    $this->post('/access', ['email' => 'alice@example.com']);
    $magicLink = magicLinkFromAccountAccessMail();

    $this->get($magicLink)->assertRedirect(route('profile.edit'));
    auth()->logout();

    $this->get($magicLink)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('access');
    $this->assertGuest();
});

test('a tampered magic link fails without creating a session', function () {
    Mail::fake();

    $this->post('/access', ['email' => 'alice@example.com']);
    $magicLink = magicLinkFromAccountAccessMail();
    $tamperedLink = preg_replace('/\/link\//', '/link/x', $magicLink) ?? '';

    $this->get($tamperedLink)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('access');
    $this->assertGuest();
    expect(Account::query()->count())->toBe(0);
});
