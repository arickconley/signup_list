<?php

namespace App\Actions;

use App\Data\CompleteSignupInput;
use App\Data\CompleteSignupResult;
use App\Data\SignupClaimTarget;
use App\Exceptions\CannotChangeSignupClaims;
use App\Exceptions\CannotCompleteSignup;
use App\Exceptions\ImmediateTransactionBusy;
use App\Mail\SignupConfirmationMail;
use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use App\Support\AccountAccessAbuseControl;
use App\Support\ImmediateDatabaseTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CompleteOpenSignup
{
    public function __construct(
        private readonly ImmediateDatabaseTransaction $immediateTransaction,
        private readonly ReplaceSignupClaims $replaceSignupClaims,
        private readonly IssueAccountAccessChallenge $issueAccountAccessChallenge,
        private readonly AccountAccessAbuseControl $abuseControl,
    ) {}

    public function handle(CompleteSignupInput $input): CompleteSignupResult
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            throw new CannotCompleteSignup('Signups are temporarily unavailable. Please try again.');
        }

        $normalizedEmail = filled($input->email) ? Account::normalizeEmail($input->email) : null;

        try {
            $writeResult = $this->immediateTransaction->run(
                fn (): array => $this->createSignup(
                    $input->sheetPublicId,
                    $input->name,
                    $input->phone,
                    $input->optionPublicIds,
                    $normalizedEmail,
                    $input->nameConsent,
                    $input->emailConsent,
                    $input->phoneConsent,
                ),
            );
        } catch (CannotCompleteSignup $exception) {
            $this->queueDuplicateAccessAfterCapacityFailure(
                $exception,
                $input->sheetPublicId,
                $normalizedEmail,
                $input->ipAddress,
            );

            throw $exception;
        } catch (ImmediateTransactionBusy $exception) {
            throw new CannotCompleteSignup(
                'The Signup Sheet is busy. Please wait a moment and try again.',
                previous: $exception,
            );
        }

        $signup = $writeResult['signup'];

        if ($normalizedEmail === null) {
            return new CompleteSignupResult(checkEmail: false);
        }

        if (! $this->abuseControl->attemptSend($normalizedEmail, $input->ipAddress)) {
            Log::warning('signup.throttled', [
                'operation' => $writeResult['duplicate'] ? 'duplicate_access_message' : 'access_message',
                'sheet_public_id' => $input->sheetPublicId,
            ]);

            return new CompleteSignupResult(checkEmail: true);
        }

        $challengePublicId = $this->queueAccessMessage($signup, $normalizedEmail);

        return new CompleteSignupResult(
            checkEmail: true,
            accessChallengePublicId: $challengePublicId,
        );
    }

    public function claim(
        ?Account $account,
        CompleteSignupInput $input,
        ?string $participationKeyHash,
    ): CompleteSignupResult {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new CannotCompleteSignup('Signups are temporarily unavailable. Please try again.');
        }

        try {
            $this->immediateTransaction->run(
                fn (): Signup => $this->appendClaim(
                    accountId: $account?->id,
                    input: $input,
                    participationKeyHash: $participationKeyHash,
                ),
            );
        } catch (CannotChangeSignupClaims $exception) {
            throw new CannotCompleteSignup(
                $exception->getMessage(),
                $exception->unavailableOptionNames,
                $exception->unavailableOptionPublicIds,
                $exception,
            );
        } catch (ImmediateTransactionBusy $exception) {
            throw new CannotCompleteSignup(
                'The Signup Sheet is busy. Please wait a moment and try again.',
                previous: $exception,
            );
        }

        return new CompleteSignupResult(checkEmail: false);
    }

    private function queueDuplicateAccessAfterCapacityFailure(
        CannotCompleteSignup $exception,
        string $sheetPublicId,
        ?string $email,
        string $ipAddress,
    ): void {
        if ($email === null || $exception->unavailableOptionPublicIds === []) {
            return;
        }

        try {
            $sheet = Sheet::query()->where('public_id', $sheetPublicId)->first(['id']);
            $signup = $sheet?->signups()
                ->where('email_snapshot', $email)
                ->first();

            if ($signup === null) {
                return;
            }

            if (! $this->abuseControl->attemptSend($email, $ipAddress)) {
                Log::warning('signup.throttled', [
                    'operation' => 'capacity_failure_access_message',
                    'sheet_public_id' => $sheetPublicId,
                ]);

                return;
            }

            $this->queueAccessMessage($signup, $email);
        } catch (Throwable $deliveryFailure) {
            Log::error('mail.dispatch_failed', [
                'operation' => 'signup_access_message',
                'sheet_public_id' => $sheetPublicId,
                'exception' => $deliveryFailure::class,
                'error' => $deliveryFailure->getMessage(),
            ]);

            // Preserve the identical capacity response even if access delivery fails.
        }
    }

    private function queueAccessMessage(Signup $signup, string $email): string
    {
        $sheet = $signup->sheet()->firstOrFail(['public_id', 'title']);
        $selectionNames = array_values($signup->optionClaims()
            ->with('option')
            ->get()
            ->sortBy(fn (OptionClaim $claim): int => $claim->option->position)
            ->map(fn (OptionClaim $claim): string => $claim->option->name)
            ->all());
        $sheetUrl = route('sheets.show', $sheet);

        $challenge = $this->issueAccountAccessChallenge->handle(
            $email,
            fn (string $code, string $magicLink, CarbonInterface $expiresAt): SignupConfirmationMail => new SignupConfirmationMail(
                sheetTitle: $sheet->title,
                sheetUrl: $sheetUrl,
                selectionNames: $selectionNames,
                code: $code,
                magicLink: $magicLink,
                expiresAt: $expiresAt->toIso8601String(),
            ),
        );

        return $challenge->public_id;
    }

    /**
     * @param  array<int, string>  $optionPublicIds
     * @return array{signup: Signup, duplicate: bool}
     */
    private function createSignup(
        string $sheetPublicId,
        string $name,
        ?string $phone,
        array $optionPublicIds,
        ?string $email,
        bool $nameConsent,
        bool $emailConsent,
        bool $phoneConsent,
    ): array {
        $sheet = Sheet::query()->where('public_id', $sheetPublicId)->first();

        if (
            $sheet === null
            || ! $sheet->isAcceptingOpenParticipationSignups()
        ) {
            throw new CannotCompleteSignup('This Signup Sheet is no longer open for signups.');
        }

        try {
            $target = $this->replaceSignupClaims->forNewSignup(
                $sheet,
                $optionPublicIds,
                function () use ($sheet, $name, $phone, $email, $nameConsent, $emailConsent, $phoneConsent): SignupClaimTarget {
                    if ($email !== null) {
                        $existingSignup = Signup::query()
                            ->where('sheet_id', $sheet->id)
                            ->where('email_snapshot', $email)
                            ->first();

                        if ($existingSignup !== null) {
                            return new SignupClaimTarget($existingSignup, alreadyComplete: true);
                        }
                    }

                    $account = null;

                    if ($email !== null) {
                        $account = Account::query()->firstOrCreate(
                            ['email' => $email],
                            [
                                'name' => $name,
                                'phone' => $phone,
                                'password' => null,
                                'timezone' => null,
                            ],
                        );
                    }

                    $signup = $sheet->signups()->create([
                        'name_snapshot' => $name,
                        'email_snapshot' => $email,
                        'phone_snapshot' => $phone,
                        'name_consent' => $sheet->name_visibility === Sheet::VISIBILITY_PARTICIPANTS
                            && $nameConsent,
                        'email_consent' => $sheet->email_visibility === Sheet::VISIBILITY_PARTICIPANTS
                            && $emailConsent,
                        'phone_consent' => $sheet->phone_visibility === Sheet::VISIBILITY_PARTICIPANTS
                            && $phoneConsent,
                    ]);

                    if ($account !== null) {
                        $signup->pendingAccountAssociation()->create(['account_id' => $account->id]);
                    }

                    return new SignupClaimTarget($signup, alreadyComplete: false);
                },
            );
        } catch (CannotChangeSignupClaims $exception) {
            throw new CannotCompleteSignup(
                $exception->getMessage(),
                $exception->unavailableOptionNames,
                $exception->unavailableOptionPublicIds,
                $exception,
            );
        }

        return ['signup' => $target->signup, 'duplicate' => $target->alreadyComplete];
    }

    private function appendClaim(
        ?int $accountId,
        CompleteSignupInput $input,
        ?string $participationKeyHash,
    ): Signup {
        $sheet = Sheet::query()
            ->where('public_id', $input->sheetPublicId)
            ->first();

        if ($sheet === null || ! $sheet->isAcceptingOpenParticipationSignups()) {
            throw new CannotCompleteSignup('This Signup Sheet is no longer open for signups.');
        }

        $account = $accountId === null
            ? null
            : Account::query()->whereKey($accountId)->first();

        if ($accountId !== null && $account === null) {
            throw new CannotCompleteSignup('This Account is no longer available.');
        }

        if ($account === null && ! preg_match('/^[a-f0-9]{64}$/', $participationKeyHash ?? '')) {
            throw new CannotCompleteSignup('This browser could not be identified. Refresh and try again.');
        }

        $signup = Signup::query()
            ->where('sheet_id', $sheet->id)
            ->when(
                $account !== null,
                fn ($query) => $query->where('account_id', $account->id),
                fn ($query) => $query->where('participation_key_hash', $participationKeyHash),
            )
            ->first();

        if ($signup === null) {
            $signup = new Signup([
                'name_snapshot' => trim($input->name),
                'email_snapshot' => null,
                'phone_snapshot' => $input->phone,
                'name_consent' => $sheet->name_visibility === Sheet::VISIBILITY_PARTICIPANTS
                    && $input->nameConsent,
                'email_consent' => false,
                'phone_consent' => $sheet->phone_visibility === Sheet::VISIBILITY_PARTICIPANTS
                    && $input->phoneConsent,
            ]);
            $signup->sheet()->associate($sheet);

            if ($account !== null) {
                $signup->account()->associate($account);
            } else {
                $signup->forceFill(['participation_key_hash' => $participationKeyHash]);
            }

            $signup->save();
        }

        $optionPublicIds = $signup->optionClaims()
            ->with('option')
            ->get()
            ->map(fn (OptionClaim $claim): string => $claim->option->public_id)
            ->all();
        $optionPublicIds = array_values(array_unique([
            ...$optionPublicIds,
            ...$input->optionPublicIds,
        ]));

        $this->replaceSignupClaims->handle($sheet, $signup, $optionPublicIds);

        return $signup;
    }
}
