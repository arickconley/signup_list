<?php

namespace App\Actions;

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

class CompleteUnregisteredSignup
{
    public function __construct(
        private readonly ImmediateDatabaseTransaction $immediateTransaction,
        private readonly IssueAccountAccessChallenge $issueAccountAccessChallenge,
        private readonly AccountAccessAbuseControl $abuseControl,
    ) {}

    /**
     * @param  array<int, string>  $optionPublicIds
     */
    public function handle(
        string $sheetPublicId,
        string $name,
        ?string $phone,
        array $optionPublicIds,
        ?string $email = null,
        string $ipAddress = 'unknown',
    ): CompleteSignupResult {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            throw new CannotCompleteSignup('Signups are temporarily unavailable. Please try again.');
        }

        $normalizedEmail = filled($email) ? Account::normalizeEmail($email) : null;

        try {
            $writeResult = $this->immediateTransaction->run(
                fn (): array => $this->createSignup(
                    $sheetPublicId,
                    $name,
                    $phone,
                    $optionPublicIds,
                    $normalizedEmail,
                ),
            );
        } catch (ImmediateTransactionBusy $exception) {
            throw new CannotCompleteSignup(
                'The signup list is busy. Please wait a moment and try again.',
                previous: $exception,
            );
        }

        $signup = $writeResult['signup'];

        if ($normalizedEmail === null) {
            return new CompleteSignupResult(checkEmail: false);
        }

        if ($writeResult['duplicate'] && ! $this->abuseControl->attemptSend($normalizedEmail, $ipAddress)) {
            return new CompleteSignupResult(checkEmail: true);
        }

        $sheet = $signup->sheet()->firstOrFail(['public_id', 'title']);
        $selectionNames = array_values($signup->optionClaims()
            ->with('option')
            ->get()
            ->sortBy(fn (OptionClaim $claim): int => $claim->option->position)
            ->map(fn (OptionClaim $claim): string => $claim->option->name)
            ->all());
        $sheetUrl = route('sheets.show', $sheet);

        $challenge = $this->issueAccountAccessChallenge->handle(
            $normalizedEmail,
            fn (string $code, string $magicLink, CarbonInterface $expiresAt): SignupConfirmationMail => new SignupConfirmationMail(
                sheetTitle: $sheet->title,
                sheetUrl: $sheetUrl,
                selectionNames: $selectionNames,
                code: $code,
                magicLink: $magicLink,
                expiresAt: $expiresAt->toIso8601String(),
            ),
        );

        return new CompleteSignupResult(
            checkEmail: true,
            accessChallengePublicId: $challenge->public_id,
        );
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
            || $sheet->state !== Sheet::STATE_PUBLISHED
            || $sheet->participation_policy !== Sheet::PARTICIPATION_OPEN
            || ! $sheet->deadline_at->isFuture()
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

        if ($email !== null) {
            $existingSignup = Signup::query()
                ->where('sheet_id', $sheet->id)
                ->where('email_snapshot', $email)
                ->first();

            if ($existingSignup !== null) {
                return ['signup' => $existingSignup, 'duplicate' => true];
            }
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
