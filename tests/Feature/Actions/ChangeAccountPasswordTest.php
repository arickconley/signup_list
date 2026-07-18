<?php

use App\Actions\ChangeAccountPassword;
use App\Enums\PasswordCredentialChange;
use App\Models\Account;
use App\Notifications\AccountPasswordChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('a verified passwordless Account can add a securely hashed password', function () {
    Notification::fake();
    $account = Account::factory()->passwordless()->create();

    app(ChangeAccountPassword::class)->set($account, 'a-new-secure-password');

    expect($account->refresh()->password)
        ->not->toBe('a-new-secure-password')
        ->and(Hash::check('a-new-secure-password', $account->password))->toBeTrue();

    Notification::assertSentTo(
        $account,
        AccountPasswordChanged::class,
        fn (AccountPasswordChanged $notification): bool => $notification->change === PasswordCredentialChange::Added
            && $notification instanceof ShouldQueue
            && $notification instanceof ShouldQueueAfterCommit
            && $notification instanceof ShouldBeEncrypted,
    );
});

test('an unverified Account cannot add a password', function () {
    Notification::fake();
    $account = Account::factory()->passwordless()->unverified()->create();

    expect(fn () => app(ChangeAccountPassword::class)->set($account, 'a-new-secure-password'))
        ->toThrow(AuthorizationException::class);

    expect($account->refresh()->password)->toBeNull();
    Notification::assertNothingSent();
});

test('a verified Account can remove its password', function () {
    Notification::fake();
    $account = Account::factory()->create();

    app(ChangeAccountPassword::class)->remove($account);

    expect($account->refresh()->password)->toBeNull();
    Notification::assertSentTo(
        $account,
        AccountPasswordChanged::class,
        fn (AccountPasswordChanged $notification): bool => $notification->change === PasswordCredentialChange::Removed,
    );
});
