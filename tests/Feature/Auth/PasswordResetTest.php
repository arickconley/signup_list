<?php

use App\Enums\PasswordCredentialChange;
use App\Models\Account;
use App\Notifications\AccountPasswordChanged;
use App\Notifications\AccountPasswordReset;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $account = Account::factory()->create();

    $this->post(route('password.request'), ['email' => $account->email]);

    Notification::assertSentTo(
        $account,
        AccountPasswordReset::class,
        fn (AccountPasswordReset $notification): bool => $notification instanceof ShouldQueue
            && $notification instanceof ShouldQueueAfterCommit
            && $notification instanceof ShouldBeEncrypted,
    );
});

test('password reset requests do not reveal whether an Account exists', function () {
    Notification::fake();
    $account = Account::factory()->create(['email' => 'existing@example.com']);

    foreach ([$account->email, 'missing@example.com'] as $email) {
        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'status',
                'If an Account matches that email, a password reset link is on its way.',
            );
    }

    Notification::assertSentToTimes($account, AccountPasswordReset::class, 1);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $account = Account::factory()->create();

    $this->post(route('password.request'), ['email' => $account->email]);

    Notification::assertSentTo($account, AccountPasswordReset::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $account = Account::factory()->create();

    $this->post(route('password.request'), ['email' => $account->email]);

    Notification::assertSentTo($account, AccountPasswordReset::class, function ($notification) use ($account) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $account->email,
            'password' => 'A-reset-password-2048!',
            'password_confirmation' => 'A-reset-password-2048!',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        expect(Hash::check('A-reset-password-2048!', $account->refresh()->password))->toBeTrue();
        Notification::assertSentTo(
            $account,
            AccountPasswordChanged::class,
            fn (AccountPasswordChanged $changed): bool => $changed->change === PasswordCredentialChange::Reset,
        );

        return true;
    });
});

test('an expired password reset link cannot change the password', function () {
    Notification::fake();
    $account = Account::factory()->create();

    $this->post(route('password.email'), ['email' => $account->email]);

    /** @var AccountPasswordReset $notification */
    $notification = Notification::sent($account, AccountPasswordReset::class)->sole();
    $this->travel(61)->minutes();

    $this->post(route('password.update'), [
        'token' => $notification->token,
        'email' => $account->email,
        'password' => 'A-reset-password-2048!',
        'password_confirmation' => 'A-reset-password-2048!',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', $account->refresh()->password))->toBeTrue();
    Notification::assertNotSentTo($account, AccountPasswordChanged::class);
});

test('a password reset link can be used only once', function () {
    Notification::fake();
    $account = Account::factory()->create();

    $this->post(route('password.email'), ['email' => $account->email]);

    /** @var AccountPasswordReset $notification */
    $notification = Notification::sent($account, AccountPasswordReset::class)->sole();
    $payload = [
        'token' => $notification->token,
        'email' => $account->email,
        'password' => 'A-first-reset-password-2048!',
        'password_confirmation' => 'A-first-reset-password-2048!',
    ];

    $this->post(route('password.update'), $payload)->assertSessionHasNoErrors();

    $this->post(route('password.update'), [
        ...$payload,
        'password' => 'A-second-reset-password-2048!',
        'password_confirmation' => 'A-second-reset-password-2048!',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('A-first-reset-password-2048!', $account->refresh()->password))->toBeTrue();
    Notification::assertSentToTimes($account, AccountPasswordChanged::class, 1);
});

test('password reset enforces the application password rules', function () {
    Notification::fake();
    $account = Account::factory()->create();

    $this->post(route('password.email'), ['email' => $account->email]);

    /** @var AccountPasswordReset $notification */
    $notification = Notification::sent($account, AccountPasswordReset::class)->sole();

    $this->post(route('password.update'), [
        'token' => $notification->token,
        'email' => $account->email,
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    expect(Hash::check('password', $account->refresh()->password))->toBeTrue();
    Notification::assertNotSentTo($account, AccountPasswordChanged::class);
});

test('an Account awaiting email reverification can use an emailed reset link', function () {
    Notification::fake();
    $account = Account::factory()->unverified()->create();

    $this->post(route('password.email'), ['email' => $account->email]);

    /** @var AccountPasswordReset $notification */
    $notification = Notification::sent($account, AccountPasswordReset::class)->sole();

    $this->post(route('password.update'), [
        'token' => $notification->token,
        'email' => $account->email,
        'password' => 'A-reset-password-2048!',
        'password_confirmation' => 'A-reset-password-2048!',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('A-reset-password-2048!', $account->refresh()->password))->toBeTrue();
});
