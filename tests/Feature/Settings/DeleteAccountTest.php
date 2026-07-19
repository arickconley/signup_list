<?php

use App\Mail\AccountDeletionVerificationMail;
use App\Models\Account;
use App\Models\Sheet;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('deletion confirmation reports accurate Signup Sheet lifecycle counts', function () {
    $this->travelTo(Carbon::parse('2026-08-01 12:00:00 UTC'));

    $account = Account::factory()->create();

    Sheet::factory()->count(2)->for($account, 'owner')->create([
        'state' => Sheet::STATE_DRAFT,
    ]);
    Sheet::factory()->for($account, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'deadline_at' => now()->addMinute(),
    ]);
    Sheet::factory()->for($account, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'deadline_at' => now(),
    ]);
    Sheet::factory()->for($account, 'owner')->create([
        'state' => Sheet::STATE_CLOSED,
    ]);
    Sheet::factory()->count(3)->for($account, 'owner')->create([
        'state' => Sheet::STATE_ARCHIVED,
    ]);

    $this->actingAs($account);

    Livewire::test('pages::settings.delete-account-modal')
        ->call('beginDeletion')
        ->assertSet('sheetCounts', [
            'draft' => 2,
            'open' => 1,
            'closed' => 2,
            'archived' => 3,
        ])
        ->assertSeeInOrder([
            'Draft Sheets',
            '2',
            'Open Sheets',
            '1',
            'Closed Sheets',
            '2',
            'Archived Sheets',
            '3',
        ]);
});

test('lifecycle counts use one authoritative instant across all categories', function () {
    $this->travelTo(Carbon::parse('2026-08-01 12:00:00 UTC'));
    $account = Account::factory()->create();
    Sheet::factory()->for($account, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
        'deadline_at' => now()->addSecond(),
    ]);
    $sheetCountQueries = 0;

    DB::listen(function (QueryExecuted $query) use (&$sheetCountQueries): void {
        if (! str_contains($query->sql, 'from "sheets"')) {
            return;
        }

        $sheetCountQueries++;

        if ($sheetCountQueries === 2) {
            Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:02 UTC'));
        }
    });

    $this->actingAs($account);

    Livewire::test('pages::settings.delete-account-modal')
        ->call('beginDeletion')
        ->assertSet('sheetCounts.open', 1)
        ->assertSet('sheetCounts.closed', 0);
});

test('deletion requires new email verification even during a freshly confirmed session', function () {
    $account = Account::factory()->create();

    $this->actingAs($account)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    Livewire::test('pages::settings.delete-account-modal')
        ->call('beginDeletion')
        ->set('password', 'password')
        ->call('deleteAccount')
        ->assertHasErrors(['verification']);

    expect($account->fresh())->not->toBeNull()
        ->and(auth()->user()?->is($account))->toBeTrue();
});

test('Account establishes fresh deletion proof with a newly emailed code', function () {
    Mail::fake();
    $account = Account::factory()->passwordless()->create();
    $this->actingAs($account);

    $component = Livewire::test('pages::settings.delete-account-modal')
        ->call('beginDeletion')
        ->call('sendDeletionVerification');

    Mail::assertQueued(AccountDeletionVerificationMail::class, fn (AccountDeletionVerificationMail $mail): bool => $mail->hasTo($account->email));

    /** @var AccountDeletionVerificationMail $mail */
    $mail = Mail::queued(AccountDeletionVerificationMail::class)->sole();

    $component
        ->set('verificationCode', $mail->code)
        ->call('verifyDeletionEmail')
        ->assertHasNoErrors()
        ->assertSet('emailVerified', true);

    expect(session('account_deletion_email_verified_account_id'))->toBe($account->id)
        ->and(session('account_deletion_email_verified_at'))->toBe(now()->timestamp);
});

test('deletion proof cannot cross an Account email change', function () {
    Mail::fake();
    $account = Account::factory()->create(['email' => 'before@example.test']);
    $this->actingAs($account);

    $component = Livewire::test('pages::settings.delete-account-modal')
        ->call('beginDeletion')
        ->call('sendDeletionVerification');

    /** @var AccountDeletionVerificationMail $mail */
    $mail = Mail::queued(AccountDeletionVerificationMail::class)->sole();

    $account->update(['email' => 'after@example.test']);

    $component
        ->set('verificationCode', $mail->code)
        ->call('verifyDeletionEmail')
        ->assertHasErrors(['verificationCode'])
        ->assertSet('emailVerified', false);

    expect(Account::query()->count())->toBe(1)
        ->and(session()->has('account_deletion_email_verified_at'))->toBeFalse();
});

test('fresh deletion proof becomes invalid if the Account email changes afterward', function () {
    Mail::fake();
    $account = Account::factory()->create(['email' => 'verified-before@example.test']);
    $this->actingAs($account);

    $component = Livewire::test('pages::settings.delete-account-modal')
        ->call('beginDeletion')
        ->call('sendDeletionVerification');

    /** @var AccountDeletionVerificationMail $mail */
    $mail = Mail::queued(AccountDeletionVerificationMail::class)->sole();

    $component
        ->set('verificationCode', $mail->code)
        ->call('verifyDeletionEmail');

    $account->update(['email' => 'verified-after@example.test']);

    $component
        ->set('password', 'password')
        ->set('confirmation', 'DELETE')
        ->call('deleteAccount')
        ->assertHasErrors(['verification']);

    expect($account->fresh())->not->toBeNull();
});

test('freshly verified deletion still requires the explicit irreversible phrase', function () {
    Mail::fake();
    $account = Account::factory()->create();
    $this->actingAs($account);

    $component = Livewire::test('pages::settings.delete-account-modal')
        ->call('beginDeletion')
        ->call('sendDeletionVerification');

    /** @var AccountDeletionVerificationMail $mail */
    $mail = Mail::queued(AccountDeletionVerificationMail::class)->sole();

    $component
        ->set('verificationCode', $mail->code)
        ->call('verifyDeletionEmail')
        ->set('password', 'password')
        ->set('confirmation', 'delete')
        ->call('deleteAccount')
        ->assertHasErrors(['confirmation']);

    expect($account->fresh())->not->toBeNull()
        ->and(auth()->user()?->is($account))->toBeTrue();
});

test('deletion email verification is stale at the challenge expiry boundary', function () {
    Mail::fake();
    $account = Account::factory()->create();
    $this->actingAs($account);

    $component = Livewire::test('pages::settings.delete-account-modal')
        ->call('beginDeletion')
        ->call('sendDeletionVerification');

    /** @var AccountDeletionVerificationMail $mail */
    $mail = Mail::queued(AccountDeletionVerificationMail::class)->sole();

    $component
        ->set('verificationCode', $mail->code)
        ->call('verifyDeletionEmail');

    $this->travel((int) config('account-access.lifetime_minutes'))->minutes();

    $component
        ->set('password', 'password')
        ->set('confirmation', 'DELETE')
        ->call('deleteAccount')
        ->assertHasErrors(['verification']);

    expect($account->fresh())->not->toBeNull();
});

test('cancelling deletion clears proof and leaves the Account unchanged', function () {
    Mail::fake();
    $account = Account::factory()->create();
    $this->actingAs($account);

    $component = Livewire::test('pages::settings.delete-account-modal')
        ->call('beginDeletion')
        ->call('sendDeletionVerification');

    /** @var AccountDeletionVerificationMail $mail */
    $mail = Mail::queued(AccountDeletionVerificationMail::class)->sole();

    $component
        ->set('verificationCode', $mail->code)
        ->call('verifyDeletionEmail')
        ->set('password', 'password')
        ->set('confirmation', 'DELETE')
        ->call('cancelDeletion')
        ->assertSet('emailVerified', false)
        ->assertSet('confirmation', '')
        ->assertSet('password', '');

    expect(session()->has('account_deletion_email_verified_at'))->toBeFalse()
        ->and($account->fresh())->not->toBeNull()
        ->and(auth()->user()?->is($account))->toBeTrue();
});

test('opening a new deletion attempt clears any prior explicit confirmation', function () {
    $account = Account::factory()->create();
    $this->actingAs($account);

    Livewire::test('pages::settings.delete-account-modal')
        ->set('password', 'password')
        ->set('confirmation', 'DELETE')
        ->call('beginDeletion')
        ->assertSet('password', '')
        ->assertSet('confirmation', '');
});

test('changed Sheet counts refresh the confirmation without deleting anything', function () {
    Mail::fake();
    $account = Account::factory()->create();
    $firstSheet = Sheet::factory()->for($account, 'owner')->create([
        'state' => Sheet::STATE_DRAFT,
    ]);
    $this->actingAs($account);

    $component = Livewire::test('pages::settings.delete-account-modal')
        ->call('beginDeletion')
        ->assertSet('sheetCounts.archived', 0);

    $newSheet = Sheet::factory()->for($account, 'owner')->create([
        'state' => Sheet::STATE_ARCHIVED,
    ]);

    $component->call('sendDeletionVerification');

    /** @var AccountDeletionVerificationMail $mail */
    $mail = Mail::queued(AccountDeletionVerificationMail::class)->sole();

    $component
        ->set('verificationCode', $mail->code)
        ->call('verifyDeletionEmail')
        ->set('password', 'password')
        ->set('confirmation', 'DELETE')
        ->call('deleteAccount')
        ->assertHasErrors(['deletion'])
        ->assertSet('sheetCounts.archived', 1)
        ->assertSet('confirmation', '');

    expect($account->fresh())->not->toBeNull()
        ->and($firstSheet->fresh())->not->toBeNull()
        ->and($newSheet->fresh())->not->toBeNull();
});

test('passwordless Account completes verified deletion and owned UUID becomes generically unavailable', function () {
    Mail::fake();
    $account = Account::factory()->passwordless()->create();
    $owner = Account::factory()->create();
    $ownedSheet = Sheet::factory()->for($account, 'owner')->create([
        'state' => Sheet::STATE_PUBLISHED,
    ]);
    $ownedOption = $ownedSheet->options()->create([
        'name' => 'Owned private Option',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $ownedSignup = $ownedSheet->signups()->create([
        'name_snapshot' => 'Owned private Signup',
    ]);
    $ownedSignup->optionClaims()->create(['option_id' => $ownedOption->id]);

    $foreignSheet = Sheet::factory()->for($owner, 'owner')->create();
    $foreignOption = $foreignSheet->options()->create([
        'name' => 'Retained Option',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $foreignSignup = $foreignSheet->signups()->create([
        'name_snapshot' => 'Identifying Participant',
        'email_snapshot' => $account->email,
        'phone_snapshot' => '555-0107',
        'name_consent' => true,
        'email_consent' => true,
        'phone_consent' => true,
    ]);
    $foreignSignup->forceFill(['account_id' => $account->id])->save();
    $foreignClaim = $foreignSignup->optionClaims()->create(['option_id' => $foreignOption->id]);

    $this->actingAs($account);

    $component = Livewire::test('pages::settings.delete-account-modal')
        ->call('beginDeletion')
        ->call('sendDeletionVerification');

    /** @var AccountDeletionVerificationMail $mail */
    $mail = Mail::queued(AccountDeletionVerificationMail::class)->sole();

    $component
        ->set('verificationCode', $mail->code)
        ->call('verifyDeletionEmail')
        ->set('confirmation', 'DELETE')
        ->call('deleteAccount')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    expect($account->fresh())->toBeNull()
        ->and($foreignSignup->refresh())
        ->account_id->toBeNull()
        ->name_snapshot->toBe('Deleted participant')
        ->email_snapshot->toBeNull()
        ->phone_snapshot->toBeNull()
        ->and($foreignClaim->fresh())->not->toBeNull()
        ->and($foreignOption->refresh()->claimed_count)->toBe(1);

    $deletedResponse = $this->get(route('sheets.show', ['sheet' => $ownedSheet->public_id]));
    $unknownResponse = $this->get(route('sheets.show', ['sheet' => (string) Str::uuid()]));

    expect($deletedResponse->getStatusCode())->toBe(404)
        ->and($deletedResponse->getContent())->toBe($unknownResponse->getContent())
        ->and($deletedResponse->getContent())->not->toContain('Owned private Option')
        ->not->toContain('Owned private Signup');

    Mail::assertQueuedCount(1);
});
