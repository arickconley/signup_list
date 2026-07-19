<?php

use App\Actions\RemoveOwnerSignup;
use App\Exceptions\CannotRemoveOwnerSignup;
use App\Mail\OwnerChangedSignupMail;
use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Support\Facades\Mail;

test('Owner removes an entire Signup and releases all of its capacity', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $sharedOption = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 3,
        'claimed_count' => 2,
        'position' => 1,
    ]);
    $releasedOption = $sheet->options()->create([
        'name' => 'Cleanup',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 2,
    ]);
    $removedSignup = $sheet->signups()->create(['name_snapshot' => 'Removed Participant']);
    $removedSignup->optionClaims()->createMany([
        ['option_id' => $sharedOption->id],
        ['option_id' => $releasedOption->id],
    ]);
    $retainedSignup = $sheet->signups()->create(['name_snapshot' => 'Retained Participant']);
    $retainedClaim = $retainedSignup->optionClaims()->create(['option_id' => $sharedOption->id]);

    app(RemoveOwnerSignup::class)->handle($owner, $sheet, $removedSignup->id);

    expect(Signup::query()->find($removedSignup->id))->toBeNull()
        ->and(OptionClaim::query()->where('signup_id', $removedSignup->id)->exists())->toBeFalse()
        ->and(Signup::query()->find($retainedSignup->id))->not->toBeNull()
        ->and(OptionClaim::query()->find($retainedClaim->id))->not->toBeNull()
        ->and($sharedOption->refresh()->claimed_count)->toBe(1)
        ->and($releasedOption->refresh()->claimed_count)->toBe(0);

    Mail::assertNothingQueued();
});

test('whole-Signup removal queues one Owner-change message with no after selections', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create(['title' => 'School fair helpers']);
    $first = $sheet->options()->create([
        'name' => 'Setup',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $second = $sheet->options()->create([
        'name' => 'Cleanup',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 2,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Emailed Participant',
        'email_snapshot' => 'participant@example.test',
    ]);
    $signup->optionClaims()->createMany([
        ['option_id' => $first->id],
        ['option_id' => $second->id],
    ]);

    app(RemoveOwnerSignup::class)->handle($owner, $sheet, $signup->id);

    Mail::assertQueuedTimes(OwnerChangedSignupMail::class, 1);
    $mail = Mail::queued(OwnerChangedSignupMail::class)->sole();

    expect($mail->hasTo('participant@example.test'))->toBeTrue()
        ->and($mail->sheetTitle)->toBe('School fair helpers')
        ->and($mail->sheetUrl)->toBe(route('sheets.show', $sheet))
        ->and($mail->beforeSelectionNames)->toBe(['Setup', 'Cleanup'])
        ->and($mail->afterSelectionNames)->toBe([])
        ->and(strip_tags($mail->render()))->toContain('No selections remain.');
});

test('another Account cannot remove an Owner Signup', function () {
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

    expect(fn () => app(RemoveOwnerSignup::class)->handle($otherAccount, $sheet, $signup->id))
        ->toThrow(CannotRemoveOwnerSignup::class, 'This Signup cannot be removed.');

    expect(Signup::query()->find($signup->id))->not->toBeNull()
        ->and(OptionClaim::query()->find($claim->id))->not->toBeNull()
        ->and($option->refresh()->claimed_count)->toBe(1);
    Mail::assertNothingQueued();
});

test('a stale whole-Signup removal request fails without changing another Signup', function () {
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
    $removedSignup = $sheet->signups()->create(['name_snapshot' => 'Removed Participant']);
    $removedSignup->optionClaims()->create(['option_id' => $removedOption->id]);
    $retainedSignup = $sheet->signups()->create(['name_snapshot' => 'Retained Participant']);
    $retainedClaim = $retainedSignup->optionClaims()->create(['option_id' => $retainedOption->id]);

    app(RemoveOwnerSignup::class)->handle($owner, $sheet, $removedSignup->id);

    expect(fn () => app(RemoveOwnerSignup::class)->handle($owner, $sheet, $removedSignup->id))
        ->toThrow(CannotRemoveOwnerSignup::class, 'This Signup cannot be removed.');

    expect(Signup::query()->find($retainedSignup->id))->not->toBeNull()
        ->and(OptionClaim::query()->find($retainedClaim->id))->not->toBeNull()
        ->and($removedOption->refresh()->claimed_count)->toBe(0)
        ->and($retainedOption->refresh()->claimed_count)->toBe(1);
    Mail::assertNothingQueued();
});

test('whole-Signup rollback preserves every claim and queues no message', function () {
    Mail::fake();

    $owner = Account::factory()->create();
    $sheet = Sheet::factory()->for($owner, 'owner')->create();
    $first = $sheet->options()->create([
        'name' => 'First',
        'capacity' => 2,
        'claimed_count' => 1,
        'position' => 1,
    ]);
    $inconsistent = $sheet->options()->create([
        'name' => 'Inconsistent',
        'capacity' => 2,
        'claimed_count' => 0,
        'position' => 2,
    ]);
    $signup = $sheet->signups()->create([
        'name_snapshot' => 'Emailed Participant',
        'email_snapshot' => 'participant@example.test',
    ]);
    $claims = $signup->optionClaims()->createMany([
        ['option_id' => $first->id],
        ['option_id' => $inconsistent->id],
    ]);

    expect(fn () => app(RemoveOwnerSignup::class)->handle($owner, $sheet, $signup->id))
        ->toThrow(LogicException::class, 'Option claimed count is inconsistent with its claims.');

    expect(Signup::query()->find($signup->id))->not->toBeNull()
        ->and(OptionClaim::query()->whereIn('id', $claims->pluck('id'))->count())->toBe(2)
        ->and($first->refresh()->claimed_count)->toBe(1)
        ->and($inconsistent->refresh()->claimed_count)->toBe(0);
    Mail::assertNothingQueued();
});

test('mail queue failure cannot roll back a committed whole-Signup removal', function () {
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
    $signup->optionClaims()->create(['option_id' => $option->id]);
    Mail::shouldReceive('to')
        ->once()
        ->with('participant@example.test')
        ->andThrow(new RuntimeException('Queue unavailable'));

    expect(fn () => app(RemoveOwnerSignup::class)->handle($owner, $sheet, $signup->id))
        ->toThrow(RuntimeException::class, 'Queue unavailable');

    expect(Signup::query()->find($signup->id))->toBeNull()
        ->and($option->refresh()->claimed_count)->toBe(0);
});
