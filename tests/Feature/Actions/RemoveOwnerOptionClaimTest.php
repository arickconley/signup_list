<?php

use App\Actions\RemoveOwnerOptionClaim;
use App\Exceptions\CannotRemoveOwnerOptionClaim;
use App\Mail\OwnerChangedSignupMail;
use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Mail;

test('Owner removes one Option Claim while preserving the Signup snapshots and other claims', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $removedOption = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $retainedOption = $sheet->options()->create([
        'name' => 'Cleanup',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 2,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Submitted Name',
        'email_snapshot' => 'submitted@example.test',
        'phone_snapshot' => '555-0102',
        'name_consent' => true,
        'email_consent' => false,
        'phone_consent' => true,
    ]);
    $removedClaim = $signup->optionClaims()->create(['option_id' => $removedOption->id]);
    $retainedClaim = $signup->optionClaims()->create(['option_id' => $retainedOption->id]);
    $snapshots = $signup->only([
        'name_snapshot',
        'email_snapshot',
        'phone_snapshot',
        'name_consent',
        'email_consent',
        'phone_consent',
    ]);

    app(RemoveOwnerOptionClaim::class)->handle($owner, $sheet, $removedClaim->id);

    expect(OptionClaim::query()->find($removedClaim->id))->toBeNull()
        ->and(OptionClaim::query()->find($retainedClaim->id))->not->toBeNull()
        ->and($removedOption->refresh()->claimed_count)->toBe(0)
        ->and($retainedOption->refresh()->claimed_count)->toBe(1)
        ->and($signup->refresh()->only(array_keys($snapshots)))->toBe($snapshots);
});

test('one-claim removal queues one immutable after-commit Owner-change message', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create(['title' => 'Neighborhood meal train']);
    $removedOption = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $retainedOption = $sheet->options()->create([
        'name' => 'Cleanup',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 2,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Emailed Participant',
        'email_snapshot' => 'participant@example.test',
    ]);
    $removedClaim = $signup->optionClaims()->create(['option_id' => $removedOption->id]);
    $signup->optionClaims()->create(['option_id' => $retainedOption->id]);

    app(RemoveOwnerOptionClaim::class)->handle($owner, $sheet, $removedClaim->id);

    Mail::assertQueuedTimes(OwnerChangedSignupMail::class, 1);
    $mail = Mail::queued(OwnerChangedSignupMail::class)->sole();

    expect($mail)
        ->toBeInstanceOf(ShouldBeEncrypted::class)
        ->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($mail->hasTo('participant@example.test'))->toBeTrue()
        ->and($mail->sheetTitle)->toBe('Neighborhood meal train')
        ->and($mail->sheetUrl)->toBe(route('sheets.show', $sheet))
        ->and($mail->beforeSelectionNames)->toBe(['Welcome table', 'Cleanup'])
        ->and($mail->afterSelectionNames)->toBe(['Cleanup']);

    $sheet->update(['title' => 'Renamed Sheet']);
    $retainedOption->update(['name' => 'Renamed Option']);

    expect(strip_tags($mail->render()))
        ->toContain('Neighborhood meal train')
        ->toContain('Welcome table')
        ->toContain('Cleanup')
        ->toContain('The Owner changed your Signup')
        ->not->toContain('Renamed Sheet')
        ->not->toContain('Renamed Option');
});

test('another Account cannot remove an Owner Option Claim', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $option = $sheet->options()->create([
        'name' => 'Protected Option',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Protected Participant',
        'email_snapshot' => 'protected@example.test',
    ]);
    $claim = $signup->optionClaims()->create(['option_id' => $option->id]);

    expect(fn () => app(RemoveOwnerOptionClaim::class)->handle($otherAccount, $sheet, $claim->id))
        ->toThrow(CannotRemoveOwnerOptionClaim::class, 'This Option Claim cannot be removed.');

    expect(OptionClaim::query()->find($claim->id))->not->toBeNull()
        ->and($option->refresh()->claimed_count)->toBe(1);
    Mail::assertNothingQueued();
});

test('a stale one-claim removal request fails without changing another claim', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $removedOption = $sheet->options()->create([
        'name' => 'Removed',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $retainedOption = $sheet->options()->create([
        'name' => 'Retained',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 2,
    ]);
    $signup = $sheet->signups()->create(['name_snapshot' => 'Participant']);
    $removedClaim = $signup->optionClaims()->create(['option_id' => $removedOption->id]);
    $retainedClaim = $signup->optionClaims()->create(['option_id' => $retainedOption->id]);

    app(RemoveOwnerOptionClaim::class)->handle($owner, $sheet, $removedClaim->id);

    expect(fn () => app(RemoveOwnerOptionClaim::class)->handle($owner, $sheet, $removedClaim->id))
        ->toThrow(CannotRemoveOwnerOptionClaim::class, 'This Option Claim cannot be removed.');

    expect(OptionClaim::query()->find($retainedClaim->id))->not->toBeNull()
        ->and($removedOption->refresh()->claimed_count)->toBe(0)
        ->and($retainedOption->refresh()->claimed_count)->toBe(1);
    Mail::assertNothingQueued();
});

test('one-claim removal queues no message without an email snapshot', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $option = $sheet->options()->create([
        'name' => 'No-email removal',
        'capacity' => 1,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create(['name_snapshot' => 'Unregistered Participant']);
    $claim = $signup->optionClaims()->create(['option_id' => $option->id]);

    app(RemoveOwnerOptionClaim::class)->handle($owner, $sheet, $claim->id);

    expect(OptionClaim::query()->find($claim->id))->toBeNull()
        ->and($option->refresh()->claimed_count)->toBe(0);
    Mail::assertNothingQueued();
});

test('one-claim rollback preserves the claim and queues no message', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $option = $sheet->options()->create([
        'name' => 'Inconsistent claim',
        'capacity' => 1,
        'claimed_count' => 0,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Emailed Participant',
        'email_snapshot' => 'participant@example.test',
    ]);
    $claim = $signup->optionClaims()->create(['option_id' => $option->id]);

    expect(fn () => app(RemoveOwnerOptionClaim::class)->handle($owner, $sheet, $claim->id))
        ->toThrow(LogicException::class, 'Option claimed count is inconsistent with its claims.');

    expect(OptionClaim::query()->find($claim->id))->not->toBeNull()
        ->and($option->refresh()->claimed_count)->toBe(0);
    Mail::assertNothingQueued();
});

test('mail queue failure cannot roll back a committed one-claim removal', function () {
    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $option = $sheet->options()->create([
        'name' => 'Committed removal',
        'capacity' => 1,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Emailed Participant',
        'email_snapshot' => 'participant@example.test',
    ]);
    $claim = $signup->optionClaims()->create(['option_id' => $option->id]);
    Mail::shouldReceive('to')
        ->once()
        ->with('participant@example.test')
        ->andThrow(new RuntimeException('Queue unavailable'));

    expect(fn () => app(RemoveOwnerOptionClaim::class)->handle($owner, $sheet, $claim->id))
        ->toThrow(RuntimeException::class, 'Queue unavailable');

    expect(OptionClaim::query()->find($claim->id))->toBeNull()
        ->and($option->refresh()->claimed_count)->toBe(0);
});
