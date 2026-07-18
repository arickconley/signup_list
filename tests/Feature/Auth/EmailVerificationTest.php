<?php

use App\Models\Account;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

test('email verification screen can be rendered', function () {
    $account = Account::factory()->unverified()->create();

    $response = $this->actingAs($account)->get(route('verification.notice'));

    $response->assertOk();
});

test('email can be verified', function () {
    $account = Account::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $account->id, 'hash' => sha1($account->email)],
    );

    $response = $this->actingAs($account)->get($verificationUrl);

    Event::assertDispatched(Verified::class);

    expect($account->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('email is not verified with invalid hash', function () {
    $account = Account::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $account->id, 'hash' => sha1('wrong-email')],
    );

    $this->actingAs($account)->get($verificationUrl);

    expect($account->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('already verified account visiting verification link is redirected without firing event again', function () {
    $account = Account::factory()->create([
        'email_verified_at' => now(),
    ]);

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $account->id, 'hash' => sha1($account->email)],
    );

    $this->actingAs($account)->get($verificationUrl)
        ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    expect($account->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertNotDispatched(Verified::class);
});
