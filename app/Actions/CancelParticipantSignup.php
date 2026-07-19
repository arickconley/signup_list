<?php

namespace App\Actions;

use App\Exceptions\CannotCancelParticipantSignup;
use App\Exceptions\ImmediateTransactionBusy;
use App\Models\Account;
use App\Models\Signup;
use App\Support\ImmediateDatabaseTransaction;
use Illuminate\Support\Facades\DB;

final class CancelParticipantSignup
{
    public function __construct(
        private readonly ImmediateDatabaseTransaction $immediateTransaction,
        private readonly ReplaceSignupClaims $replaceSignupClaims,
    ) {}

    public function handle(Account $account, int $signupId): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new CannotCancelParticipantSignup('This Signup cannot be cancelled right now. Please try again.');
        }

        try {
            $this->immediateTransaction->run(function () use ($account, $signupId): void {
                $this->cancelSignup($account->id, $signupId);
            });
        } catch (ImmediateTransactionBusy $exception) {
            throw new CannotCancelParticipantSignup(
                'The Signup Sheet is busy. Please wait a moment and try again.',
                $exception,
            );
        }
    }

    private function cancelSignup(int $accountId, int $signupId): void
    {
        $account = Account::query()->whereKey($accountId)->first();
        $signup = Signup::query()->with('sheet')->whereKey($signupId)->first();

        if ($account === null || $signup === null || ! $signup->canBeCancelledBy($account)) {
            throw new CannotCancelParticipantSignup('You cannot cancel this Signup.');
        }

        $this->replaceSignupClaims->releaseAll($signup);
        $signup->delete();
    }
}
