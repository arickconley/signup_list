<?php

use App\Actions\CompleteUnregisteredSignup;
use App\Mail\SignupConfirmationMail;
use App\Models\Account;
use App\Models\AccountAccessChallenge;
use App\Models\OptionClaim;
use App\Models\PendingAccountAssociation;
use App\Models\Sheet;
use App\Models\Signup;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

test('participant supplies email and immediately completes a pending Account Signup', function () {
    Mail::fake();

    $sheet = Sheet::factory()->create([
        'title' => 'Neighborhood supper',
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Bring dessert',
        'capacity' => 2,
        'position' => 1,
    ]);

    Livewire::test('complete-unregistered-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', '  Jordan Lee  ')
        ->set('email', '  Jordan@Example.COM  ')
        ->set('phone', '  555-0102  ')
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSee('If the address can receive email');

    $account = Account::query()->where('email', 'jordan@example.com')->sole();
    $signup = Signup::query()->with(['optionClaims', 'pendingAccountAssociation'])->sole();

    expect($account)
        ->name->toBe('Jordan Lee')
        ->phone->toBe('555-0102')
        ->password->toBeNull()
        ->email_verified_at->toBeNull()
        ->and($signup)
        ->name_snapshot->toBe('Jordan Lee')
        ->email_snapshot->toBe('jordan@example.com')
        ->phone_snapshot->toBe('555-0102')
        ->account_id->toBeNull()
        ->name_consent->toBeFalse()
        ->email_consent->toBeFalse()
        ->phone_consent->toBeFalse()
        ->and($signup->pendingAccountAssociation)->not->toBeNull()
        ->account_id->toBe($account->id)
        ->and($signup->optionClaims)->toHaveCount(1)
        ->and($option->refresh()->claimed_count)->toBe(1)
        ->and($signup->canBeEditedBy($account))->toBeFalse()
        ->and($signup->canBeCancelledBy($account))->toBeFalse();

    Mail::assertQueued(SignupConfirmationMail::class, function (SignupConfirmationMail $mail): bool {
        return $mail->hasTo('jordan@example.com')
            && $mail->sheetTitle === 'Neighborhood supper'
            && $mail->selectionNames === ['Bring dessert'];
    });
    Mail::assertQueuedCount(1);

    /** @var SignupConfirmationMail $mail */
    $mail = Mail::queued(SignupConfirmationMail::class)->sole();

    expect($mail)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($mail)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($mail->sheetTitle)->toBeString()
        ->and($mail->sheetUrl)->toBeString()
        ->and($mail->selectionNames)->toBe(['Bring dessert'])
        ->and($mail->expiresAt)->toBeString();

    $sheet->update(['title' => 'Changed later']);
    $option->update(['name' => 'Changed selection later']);
    $account->update(['name' => 'Changed profile later', 'phone' => '555-0199']);
    $rendered = $mail->render();

    expect($rendered)->toContain('Neighborhood supper')
        ->toContain('Bring dessert')
        ->not->toContain('Changed later')
        ->not->toContain('Changed selection later');

    preg_match_all('/\b(\d{6})\b/', strip_tags($rendered), $codeMatches);
    preg_match('/href="([^"]*\/access\/[^"]*)"/', $rendered, $linkMatches);

    $code = $codeMatches[1][0] ?? '';
    $magicLink = html_entity_decode($linkMatches[1] ?? '');
    $challenge = AccountAccessChallenge::query()->sole();
    $magicLinkPath = parse_url($magicLink, PHP_URL_PATH);
    $magicLinkSegments = is_string($magicLinkPath)
        ? explode('/', trim($magicLinkPath, '/'))
        : [];

    expect($codeMatches[1])->toHaveCount(1)
        ->and(Hash::check($code, $challenge->code_hash))->toBeTrue()
        ->and($magicLink)->not->toBeEmpty()
        ->and(URL::hasValidSignature(Request::create($magicLink)))->toBeTrue()
        ->and($magicLinkSegments[1] ?? null)->toBe($challenge->public_id)
        ->and(Hash::check($magicLinkSegments[3] ?? '', $challenge->token_hash))->toBeTrue();
});

test('duplicate normalized email is neutral and changes no Signup capacity', function () {
    Mail::fake();

    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Welcome table',
        'capacity' => 1,
        'position' => 1,
    ]);

    $complete = fn (string $email, string $name) => Livewire::test(
        'complete-unregistered-signup',
        ['sheetPublicId' => $sheet->public_id],
    )
        ->set('name', $name)
        ->set('email', $email)
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSee('If the address can receive email, confirmation and an access link are on the way.');

    $complete('Casey@Example.com', 'Casey Original');
    $complete('  CASEY@example.COM ', 'Casey Duplicate');

    expect(Signup::query()->count())->toBe(1)
        ->and(Signup::query()->sole()->name_snapshot)->toBe('Casey Original')
        ->and(OptionClaim::query()->count())->toBe(1)
        ->and(PendingAccountAssociation::query()->count())->toBe(1)
        ->and($option->refresh()->claimed_count)->toBe(1);
    Mail::assertQueuedTimes(SignupConfirmationMail::class, 2);

    /** @var SignupConfirmationMail $duplicateMail */
    $duplicateMail = Mail::queued(SignupConfirmationMail::class)->last();
    $rendered = $duplicateMail->render();

    preg_match_all('/\b(\d{6})\b/', strip_tags($rendered), $codeMatches);
    preg_match('/href="([^"]*\/access\/[^"]*)"/', $rendered, $linkMatches);

    expect($codeMatches[1])->toHaveCount(1)
        ->and(URL::hasValidSignature(Request::create(
            html_entity_decode($linkMatches[1] ?? ''),
        )))->toBeTrue();

    $complete('casey@example.com', 'Casey Repeated');

    expect(Signup::query()->count())->toBe(1)
        ->and(OptionClaim::query()->count())->toBe(1)
        ->and(PendingAccountAssociation::query()->count())->toBe(1)
        ->and($option->refresh()->claimed_count)->toBe(1);
    Mail::assertQueuedTimes(SignupConfirmationMail::class, 2);
});

test('existing Account profile is not overwritten by a pending Signup', function () {
    Mail::fake();

    $account = Account::factory()->create([
        'name' => 'Existing Profile Name',
        'email' => 'existing@example.com',
        'phone' => '555-0111',
        'timezone' => 'America/New_York',
    ]);
    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Cleanup',
        'capacity' => 2,
        'position' => 1,
    ]);

    Livewire::test('complete-unregistered-signup', ['sheetPublicId' => $sheet->public_id])
        ->set('name', 'Submitted Snapshot Name')
        ->set('email', ' EXISTING@EXAMPLE.COM ')
        ->set('phone', '555-0222')
        ->set('selectedOptions', [$option->public_id])
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSee('If the address can receive email')
        ->assertDontSee('Existing Profile Name');

    $signup = Signup::query()->with('pendingAccountAssociation')->sole();

    expect($account->refresh())
        ->name->toBe('Existing Profile Name')
        ->phone->toBe('555-0111')
        ->timezone->toBe('America/New_York')
        ->and(Account::query()->where('email', 'existing@example.com')->count())->toBe(1)
        ->and($signup)
        ->name_snapshot->toBe('Submitted Snapshot Name')
        ->email_snapshot->toBe('existing@example.com')
        ->phone_snapshot->toBe('555-0222')
        ->account_id->toBeNull()
        ->and($signup->pendingAccountAssociation?->account_id)->toBe($account->id);
});

test('email-backed completion queues nothing unless its immediate transaction commits', function () {
    Mail::fake();

    $sheet = Sheet::factory()->create([
        'state' => Sheet::STATE_PUBLISHED,
        'participation_policy' => Sheet::PARTICIPATION_OPEN,
        'selection_maximum' => 1,
    ]);
    $option = $sheet->options()->create([
        'name' => 'Rejected claim',
        'capacity' => 1,
        'position' => 1,
    ]);

    DB::unprepared(<<<'SQL'
        CREATE TRIGGER reject_email_option_claim
        BEFORE INSERT ON option_claims
        BEGIN
            SELECT RAISE(ABORT, 'reject email Option Claim');
        END
        SQL);

    expect(fn () => app(CompleteUnregisteredSignup::class)->handle(
        $sheet->public_id,
        'Rollback Participant',
        '555-0300',
        [$option->public_id],
        'rollback@example.com',
        '192.0.2.10',
    ))->toThrow(QueryException::class);

    expect(Account::query()->where('email', 'rollback@example.com')->exists())->toBeFalse()
        ->and(Signup::query()->count())->toBe(0)
        ->and(OptionClaim::query()->count())->toBe(0)
        ->and($option->refresh()->claimed_count)->toBe(0)
        ->and(AccountAccessChallenge::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

test('new and existing Account email submissions expose the same neutral response', function () {
    Mail::fake();

    Account::factory()->create([
        'name' => 'Private Existing Profile',
        'email' => 'known@example.com',
    ]);

    $announcements = [];

    foreach (['known@example.com', 'new@example.com'] as $index => $email) {
        $sheet = Sheet::factory()->create([
            'state' => Sheet::STATE_PUBLISHED,
            'participation_policy' => Sheet::PARTICIPATION_OPEN,
            'selection_maximum' => 1,
        ]);
        $option = $sheet->options()->create([
            'name' => 'Neutral Option',
            'capacity' => 1,
            'position' => 1,
        ]);

        $component = Livewire::test('complete-unregistered-signup', ['sheetPublicId' => $sheet->public_id])
            ->set('name', 'Neutral Participant')
            ->set('email', $email)
            ->set('selectedOptions', [$option->public_id])
            ->call('complete')
            ->assertHasNoErrors()
            ->assertSet('completed', true)
            ->assertSet('checkEmail', true)
            ->assertSee('If the address can receive email')
            ->assertDontSee('Private Existing Profile')
            ->assertDontSee('already registered');

        $announcements[$index] = $component->get('announcement');
    }

    expect($announcements[0])->toBe($announcements[1]);
    Mail::assertQueuedTimes(SignupConfirmationMail::class, 2);
});

test('database enforces one normalized email snapshot per Signup Sheet', function () {
    $sheet = Sheet::factory()->create();

    $sheet->signups()->create([
        'name_snapshot' => 'First Signup',
        'email_snapshot' => 'unique@example.com',
    ]);

    expect(fn () => $sheet->signups()->create([
        'name_snapshot' => 'Second Signup',
        'email_snapshot' => 'unique@example.com',
    ]))->toThrow(QueryException::class);
});
