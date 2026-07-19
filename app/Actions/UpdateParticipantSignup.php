<?php

namespace App\Actions;

use App\Data\UpdateParticipantSignupInput;
use App\Exceptions\CannotChangeSignupClaims;
use App\Exceptions\CannotUpdateParticipantSignup;
use App\Exceptions\ImmediateTransactionBusy;
use App\Models\Account;
use App\Models\Signup;
use App\Support\ImmediateDatabaseTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UpdateParticipantSignup
{
    public function __construct(
        private readonly ImmediateDatabaseTransaction $immediateTransaction,
        private readonly ReplaceSignupClaims $replaceSignupClaims,
    ) {}

    public function handle(Account $account, UpdateParticipantSignupInput $input): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new CannotUpdateParticipantSignup('This Signup cannot be edited right now. Please try again.');
        }

        try {
            $this->immediateTransaction->run(function () use ($account, $input): void {
                $this->updateSignup($account->id, $input);
            });
        } catch (CannotChangeSignupClaims $exception) {
            throw new CannotUpdateParticipantSignup(
                $exception->getMessage(),
                $exception->unavailableOptionNames,
                $exception->unavailableOptionPublicIds,
                $exception,
            );
        } catch (ImmediateTransactionBusy $exception) {
            throw new CannotUpdateParticipantSignup(
                'The Signup Sheet is busy. Please wait a moment and try again.',
                previous: $exception,
            );
        }
    }

    private function updateSignup(int $accountId, UpdateParticipantSignupInput $input): void
    {
        $account = Account::query()->whereKey($accountId)->first();
        $signup = Signup::query()->with('sheet')->whereKey($input->signupId)->first();

        if ($account === null || $signup === null || ! $signup->canBeEditedBy($account)) {
            throw new CannotUpdateParticipantSignup('You cannot edit this Signup.');
        }

        $name = trim($input->name);
        $phone = filled($input->phone) ? trim($input->phone) : null;

        if ($name === '' || Str::length($name) > 255 || ($phone !== null && Str::length($phone) > 50)) {
            throw new CannotUpdateParticipantSignup('Review the Signup details and try again.');
        }

        $this->replaceSignupClaims->handle(
            $signup->sheet,
            $signup,
            $input->optionPublicIds,
            allowExistingOverLimit: true,
        );

        $signup->update([
            'name_snapshot' => $name,
            'phone_snapshot' => $phone,
            'name_consent' => $input->nameConsent,
            'email_consent' => $input->emailConsent,
            'phone_consent' => $input->phoneConsent,
        ]);
    }
}
