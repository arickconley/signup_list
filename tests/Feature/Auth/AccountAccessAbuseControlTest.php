<?php

use App\Support\AccountAccessAbuseControl;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\travel;

test('send attempts enforce the configured resend cooldown', function () {
    config()->set('account-access.send_limit_per_email', 10);
    config()->set('account-access.send_limit_per_ip', 10);
    config()->set('account-access.resend_cooldown_seconds', 60);

    $abuseControl = app(AccountAccessAbuseControl::class);

    expect($abuseControl->attemptSend('alice@example.com', '192.0.2.10'))->toBeTrue()
        ->and($abuseControl->attemptSend('alice@example.com', '192.0.2.10'))->toBeFalse();

    travel(60)->seconds();

    expect($abuseControl->attemptSend('alice@example.com', '192.0.2.10'))->toBeTrue();
});

test('cooldown denials do not consume the address send allowance', function () {
    config()->set('account-access.send_limit_per_email', 2);
    config()->set('account-access.send_limit_per_ip', 10);
    config()->set('account-access.resend_cooldown_seconds', 10);

    $abuseControl = app(AccountAccessAbuseControl::class);

    expect($abuseControl->attemptSend('alice@example.com', '192.0.2.10'))->toBeTrue()
        ->and($abuseControl->attemptSend('alice@example.com', '192.0.2.10'))->toBeFalse();

    travel(10)->seconds();

    expect($abuseControl->attemptSend('alice@example.com', '192.0.2.10'))->toBeTrue();
});

test('send attempts share limits across normalized email variants', function () {
    config()->set('account-access.send_limit_per_email', 10);
    config()->set('account-access.send_limit_per_ip', 10);
    config()->set('account-access.resend_cooldown_seconds', 60);

    $abuseControl = app(AccountAccessAbuseControl::class);

    expect($abuseControl->attemptSend('  Alice@Example.COM ', '192.0.2.10'))->toBeTrue()
        ->and($abuseControl->attemptSend('alice@example.com', '192.0.2.11'))->toBeFalse();
});

test('send attempts enforce the configured email limit', function () {
    config()->set('account-access.send_limit_per_email', 2);
    config()->set('account-access.send_limit_per_ip', 10);
    config()->set('account-access.resend_cooldown_seconds', 1);

    $abuseControl = app(AccountAccessAbuseControl::class);

    expect($abuseControl->attemptSend('alice@example.com', '192.0.2.10'))->toBeTrue();

    travel(1)->seconds();

    expect($abuseControl->attemptSend('alice@example.com', '192.0.2.11'))->toBeTrue();

    travel(1)->seconds();

    expect($abuseControl->attemptSend('alice@example.com', '192.0.2.12'))->toBeFalse();
});

test('send attempts enforce the configured IP limit', function () {
    config()->set('account-access.send_limit_per_email', 10);
    config()->set('account-access.send_limit_per_ip', 2);
    config()->set('account-access.resend_cooldown_seconds', 60);

    $abuseControl = app(AccountAccessAbuseControl::class);

    expect($abuseControl->attemptSend('alice@example.com', '192.0.2.10'))->toBeTrue()
        ->and($abuseControl->attemptSend('bob@example.com', '192.0.2.10'))->toBeTrue()
        ->and($abuseControl->attemptSend('carol@example.com', '192.0.2.10'))->toBeFalse();
});

test('verification attempts enforce the configured normalized email limit', function () {
    config()->set('account-access.verification_limit_per_email', 2);
    config()->set('account-access.verification_limit_per_ip', 10);

    $abuseControl = app(AccountAccessAbuseControl::class);

    expect($abuseControl->attemptVerification('  Alice@Example.COM ', '192.0.2.10'))->toBeTrue()
        ->and($abuseControl->attemptVerification('alice@example.com', '192.0.2.11'))->toBeTrue()
        ->and($abuseControl->attemptVerification('ALICE@EXAMPLE.COM', '192.0.2.12'))->toBeFalse();
});

test('verification attempts enforce the configured IP limit', function () {
    config()->set('account-access.verification_limit_per_email', 10);
    config()->set('account-access.verification_limit_per_ip', 2);

    $abuseControl = app(AccountAccessAbuseControl::class);

    expect($abuseControl->attemptVerification('alice@example.com', '192.0.2.10'))->toBeTrue()
        ->and($abuseControl->attemptVerification('bob@example.com', '192.0.2.10'))->toBeTrue()
        ->and($abuseControl->attemptVerification('carol@example.com', '192.0.2.10'))->toBeFalse();
});

test('rate limit cache keys contain no raw account access identifiers', function () {
    $abuseControl = app(AccountAccessAbuseControl::class);

    $abuseControl->attemptSend('  Alice@Example.COM ', '192.0.2.10');
    $abuseControl->attemptVerification('  Alice@Example.COM ', '192.0.2.10');

    $store = Cache::getStore();

    if (! $store instanceof ArrayStore) {
        throw new LogicException('This test requires the array cache store.');
    }

    $keys = implode("\n", array_keys($store->all()));

    expect(str_contains($keys, 'Alice@Example.COM'))->toBeFalse()
        ->and(str_contains($keys, 'alice@example.com'))->toBeFalse()
        ->and(str_contains($keys, '192.0.2.10'))->toBeFalse();
});

test('rate limit bucket identity is keyed by the application secret', function () {
    config()->set('account-access.send_limit_per_email', 1);
    config()->set('account-access.send_limit_per_ip', 1);
    config()->set('account-access.resend_cooldown_seconds', 60);
    config()->set('app.key', 'first-account-access-key');

    $abuseControl = app(AccountAccessAbuseControl::class);

    expect($abuseControl->attemptSend('alice@example.com', '192.0.2.10'))->toBeTrue();

    config()->set('app.key', 'second-account-access-key');

    expect($abuseControl->attemptSend('alice@example.com', '192.0.2.10'))->toBeTrue();
});
