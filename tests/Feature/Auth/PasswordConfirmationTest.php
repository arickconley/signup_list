<?php

use App\Mail\AccountAccessMail;
use App\Models\Account;
use App\Models\AccountAccessChallenge;
use Illuminate\Support\Facades\Mail;

function accountConfirmationCode(): string
{
    /** @var AccountAccessMail $mail */
    $mail = Mail::queued(AccountAccessMail::class)->sole();
    preg_match('/\b(\d{6})\b/', strip_tags($mail->render()), $matches);

    return $matches[1] ?? '';
}

function accountConfirmationMagicLink(): string
{
    /** @var AccountAccessMail $mail */
    $mail = Mail::queued(AccountAccessMail::class)->sole();
    preg_match('/href="([^"]*\/access\/[^"]*)"/', $mail->render(), $matches);

    return html_entity_decode($matches[1] ?? '');
}

test('confirm password screen can be rendered', function () {
    $account = Account::factory()->create();

    $response = $this->actingAs($account)->get(route('password.confirm'));

    $response->assertOk();
});

test('a verified passwordless Account can freshly authenticate by email', function () {
    Mail::fake();
    $account = Account::factory()->passwordless()->create();

    $this->actingAs($account)
        ->get(route('security.edit'))
        ->assertRedirect(route('password.confirm'));

    $this->get(route('password.confirm'))
        ->assertOk()
        ->assertSee('Email me a confirmation code')
        ->assertDontSeeHtml('name="password"');

    $this->post(route('account-access.request'))
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHas('status', 'If the address can receive email, a sign-in code is on its way.');

    Mail::assertQueued(AccountAccessMail::class, fn (AccountAccessMail $mail): bool => $mail->hasTo($account->email));

    $this->post(route('account-access.code'), ['code' => accountConfirmationCode()])
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('auth.password_confirmed_at');

    $this->get(route('security.edit'))->assertOk();
});

test('authenticated reauthentication requests are bound to the current Account email', function () {
    Mail::fake();
    $account = Account::factory()->create(['email' => 'current@example.com']);

    $this->actingAs($account)
        ->post(route('account-access.request'), ['email' => 'other@example.com'])
        ->assertRedirect(route('password.confirm'));

    Mail::assertQueued(AccountAccessMail::class, function (AccountAccessMail $mail): bool {
        return $mail->hasTo('current@example.com') && ! $mail->hasTo('other@example.com');
    });
    expect(AccountAccessChallenge::query()->sole()->email)->toBe('current@example.com');
});

test("an authenticated Account cannot consume another Account's confirmation code", function () {
    Mail::fake();
    $victim = Account::factory()->create(['email' => 'victim@example.com']);

    $this->post(route('account-access.request'), ['email' => $victim->email]);
    $code = accountConfirmationCode();
    $challenge = AccountAccessChallenge::query()->sole();
    $attacker = Account::factory()->create(['email' => 'attacker@example.com']);

    $this->actingAs($attacker)
        ->post(route('account-access.code'), ['code' => $code])
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHasErrors('access');

    $this->assertAuthenticatedAs($attacker);
    expect($challenge->refresh()->used_at)->toBeNull();
});

test("an authenticated Account cannot consume another Account's magic link", function () {
    Mail::fake();
    $victim = Account::factory()->create(['email' => 'victim@example.com']);

    $this->post(route('account-access.request'), ['email' => $victim->email]);
    $magicLink = accountConfirmationMagicLink();
    $challenge = AccountAccessChallenge::query()->sole();
    $attacker = Account::factory()->create(['email' => 'attacker@example.com']);

    $this->actingAs($attacker)
        ->get($magicLink)
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHasErrors('access');

    $this->assertAuthenticatedAs($attacker);
    expect($challenge->refresh()->used_at)->toBeNull();
});
