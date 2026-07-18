<?php

namespace App\Actions;

use App\Data\CompleteSignupInput;
use App\Data\CompleteSignupResult;
use App\Exceptions\CannotCompleteSignup;
use App\Exceptions\ImmediateTransactionBusy;
use App\Mail\SignupConfirmationMail;
use App\Models\Account;
use App\Models\Option;
use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use App\Support\AccountAccessAbuseControl;
use App\Support\ImmediateDatabaseTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompleteOpenSignup
{
    public function __construct(
        private readonly ImmediateDatabaseTransaction $immediateTransaction,
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

        if ($writeResult['duplicate'] && ! $this->abuseControl->attemptSend($normalizedEmail, $input->ipAddress)) {
            return new CompleteSignupResult(checkEmail: true);
        }

        $challengePublicId = $this->queueAccessMessage($signup, $normalizedEmail);

        return new CompleteSignupResult(
            checkEmail: true,
            accessChallengePublicId: $challengePublicId,
        );
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

            if ($signup === null || ! $this->abuseControl->attemptSend($email, $ipAddress)) {
                return;
            }

            $this->queueAccessMessage($signup, $email);
        } catch (Throwable) {
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
    ): array {
        $sheet = Sheet::query()->where('public_id', $sheetPublicId)->first();

        if (
            $sheet === null
            || ! $sheet->isAcceptingSignups()
        ) {
            throw new CannotCompleteSignup('This Signup Sheet is no longer open for signups.');
        }

        $selectionMaximum = $sheet->selection_maximum;
        $uniqueOptionPublicIds = array_values(array_unique($optionPublicIds));

        if (
            $selectionMaximum === null
            || count($uniqueOptionPublicIds) !== count($optionPublicIds)
            || count($optionPublicIds) < 1
            || count($optionPublicIds) > $selectionMaximum
        ) {
            throw new CannotCompleteSignup(
                "Choose between 1 and {$selectionMaximum} available Options.",
            );
        }

        $options = Option::query()
            ->where('sheet_id', $sheet->id)
            ->whereIn('public_id', $optionPublicIds)
            ->orderBy('id')
            ->get();

        if ($options->count() !== count($optionPublicIds)) {
            throw new CannotCompleteSignup('One or more selected Options do not belong to this Signup Sheet.');
        }

        $unavailableOptions = $options->filter(
            fn (Option $option): bool => $option->claimed_count >= $option->capacity,
        );

        if ($unavailableOptions->isNotEmpty()) {
            throw new CannotCompleteSignup(
                'Some selected Options just became unavailable. Choose another Option and try again.',
                $unavailableOptions->pluck('name')->all(),
                $unavailableOptions->pluck('public_id')->all(),
            );
        }

        if ($email !== null) {
            $existingSignup = Signup::query()
                ->where('sheet_id', $sheet->id)
                ->where('email_snapshot', $email)
                ->first();

            if ($existingSignup !== null) {
                return ['signup' => $existingSignup, 'duplicate' => true];
            }
        }

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

            $accountDefaults = $account->accountDefaults();
            $name = filled($accountDefaults->name) ? $accountDefaults->name : $name;
            $email = filled($accountDefaults->email) ? $accountDefaults->email : $email;
            $phone = filled($accountDefaults->phone) ? $accountDefaults->phone : $phone;
        }

        $signup = $sheet->signups()->create([
            'name_snapshot' => $name,
            'email_snapshot' => $email,
            'phone_snapshot' => $phone,
        ]);

        if (isset($account)) {
            $signup->pendingAccountAssociation()->create(['account_id' => $account->id]);
        }

        foreach ($options as $option) {
            $updated = Option::query()
                ->whereKey($option->id)
                ->whereColumn('claimed_count', '<', 'capacity')
                ->increment('claimed_count');

            if ($updated !== 1) {
                throw new CannotCompleteSignup(
                    'Some selected Options just became unavailable. Choose another Option and try again.',
                    [$option->name],
                    [$option->public_id],
                );
            }

            $signup->optionClaims()->create(['option_id' => $option->id]);
        }

        return ['signup' => $signup, 'duplicate' => false];
    }
}
