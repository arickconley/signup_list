<?php

namespace App\Actions;

use App\Data\CompleteSignupInput;
use App\Data\CompleteSignupResult;
use App\Exceptions\CannotCompleteSignup;
use App\Exceptions\ImmediateTransactionBusy;
use App\Models\Account;
use App\Models\Option;
use App\Models\Sheet;
use App\Models\Signup;
use App\Support\ImmediateDatabaseTransaction;
use Illuminate\Support\Facades\DB;

final class CompleteVerifiedSignup
{
    public function __construct(
        private readonly ImmediateDatabaseTransaction $immediateTransaction,
        private readonly AttachPendingAccountAssociations $attachPendingAccountAssociations,
    ) {}

    public function handle(Account $account, CompleteSignupInput $input): CompleteSignupResult
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new CannotCompleteSignup('Signups are temporarily unavailable. Please try again.');
        }

        try {
            $this->immediateTransaction->run(
                fn (): Signup => $this->createSignup($account->id, $input),
            );
        } catch (ImmediateTransactionBusy $exception) {
            throw new CannotCompleteSignup(
                'The Signup Sheet is busy. Please wait a moment and try again.',
                previous: $exception,
            );
        }

        return new CompleteSignupResult(checkEmail: false);
    }

    private function createSignup(int $accountId, CompleteSignupInput $input): Signup
    {
        $account = Account::query()->whereKey($accountId)->first();

        if ($account === null || ! $account->hasVerifiedEmail()) {
            throw new CannotCompleteSignup('Verify your Account email before completing this Signup.');
        }

        $name = trim($input->name);
        $phone = $input->phone === null || trim($input->phone) === ''
            ? null
            : trim($input->phone);
        $email = $input->email === null || trim($input->email) === ''
            ? null
            : Account::normalizeEmail($input->email);
        $accountEmail = $account->accountDefaults()->email;

        if ($name === '' || $email === null || $email !== $accountEmail) {
            throw new CannotCompleteSignup('Your name and verified Account email are required.');
        }

        $sheet = Sheet::query()
            ->where('public_id', $input->sheetPublicId)
            ->first();

        if ($sheet === null || ! $sheet->isAcceptingVerifiedParticipationSignups()) {
            throw new CannotCompleteSignup('This Signup Sheet is no longer open for Verified Participation.');
        }

        $existingSignup = $this->attachPendingAccountAssociations->handleForSheet($account, $sheet);

        if ($existingSignup !== null) {
            return $existingSignup;
        }

        $selectionMaximum = $sheet->selection_maximum;
        $uniqueOptionPublicIds = array_values(array_unique($input->optionPublicIds));

        if (
            $selectionMaximum === null
            || count($uniqueOptionPublicIds) !== count($input->optionPublicIds)
            || count($input->optionPublicIds) < 1
            || count($input->optionPublicIds) > $selectionMaximum
        ) {
            throw new CannotCompleteSignup(
                "Choose between 1 and {$selectionMaximum} available Options.",
            );
        }

        $options = Option::query()
            ->where('sheet_id', $sheet->id)
            ->whereIn('public_id', $input->optionPublicIds)
            ->orderBy('id')
            ->get();

        if ($options->count() !== count($input->optionPublicIds)) {
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

        $signup = new Signup([
            'name_snapshot' => $name,
            'email_snapshot' => $accountEmail,
            'phone_snapshot' => $phone,
            'name_consent' => $sheet->name_visibility === Sheet::VISIBILITY_PARTICIPANTS
                && $input->nameConsent,
            'email_consent' => $sheet->email_visibility === Sheet::VISIBILITY_PARTICIPANTS
                && $input->emailConsent,
            'phone_consent' => $sheet->phone_visibility === Sheet::VISIBILITY_PARTICIPANTS
                && $input->phoneConsent,
        ]);
        $signup->sheet()->associate($sheet);
        $signup->account()->associate($account);
        $signup->save();

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

        return $signup;
    }
}
