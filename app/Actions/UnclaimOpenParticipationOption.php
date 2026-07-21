<?php

namespace App\Actions;

use App\Exceptions\CannotUnclaimOpenParticipationOption;
use App\Exceptions\ImmediateTransactionBusy;
use App\Models\Account;
use App\Models\Option;
use App\Models\Sheet;
use App\Models\Signup;
use App\Support\ImmediateDatabaseTransaction;
use Illuminate\Support\Facades\DB;

final class UnclaimOpenParticipationOption
{
    public function __construct(
        private readonly ImmediateDatabaseTransaction $immediateTransaction,
        private readonly ReplaceSignupClaims $replaceSignupClaims,
    ) {}

    public function handle(
        ?Account $account,
        string $sheetPublicId,
        string $optionPublicId,
        ?string $participationKeyHash,
    ): void {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new CannotUnclaimOpenParticipationOption(
                'This Option Claim cannot be unclaimed right now. Please try again.',
            );
        }

        try {
            $this->immediateTransaction->run(function () use (
                $account,
                $sheetPublicId,
                $optionPublicId,
                $participationKeyHash,
            ): void {
                $this->unclaim(
                    $account?->id,
                    $sheetPublicId,
                    $optionPublicId,
                    $participationKeyHash,
                );
            });
        } catch (ImmediateTransactionBusy $exception) {
            throw new CannotUnclaimOpenParticipationOption(
                'The Signup Sheet is busy. Please wait a moment and try again.',
                $exception,
            );
        }
    }

    private function unclaim(
        ?int $accountId,
        string $sheetPublicId,
        string $optionPublicId,
        ?string $participationKeyHash,
    ): void {
        $sheet = Sheet::query()
            ->where('public_id', $sheetPublicId)
            ->first();

        if ($sheet === null || ! $sheet->isAcceptingOpenParticipationSignups()) {
            $this->reject();
        }

        $account = $accountId === null
            ? null
            : Account::query()->whereKey($accountId)->first();

        if ($accountId !== null && $account === null) {
            $this->reject();
        }

        if ($account === null && preg_match('/\A[a-f0-9]{64}\z/', $participationKeyHash ?? '') !== 1) {
            $this->reject();
        }

        $signup = $this->findSignup($sheet, $account, $participationKeyHash);
        $option = Option::query()
            ->where('sheet_id', $sheet->id)
            ->where('public_id', $optionPublicId)
            ->first();

        if ($signup === null || $option === null) {
            $this->reject();
        }

        $claim = $signup->optionClaims()
            ->where('option_id', $option->id)
            ->first();

        if ($claim === null) {
            $this->reject();
        }

        $this->replaceSignupClaims->releaseOne($claim);

        if (! $signup->optionClaims()->exists()) {
            $signup->delete();
        }
    }

    private function findSignup(
        Sheet $sheet,
        ?Account $account,
        ?string $participationKeyHash,
    ): ?Signup {
        $query = $sheet->signups();

        if ($account !== null) {
            return $query
                ->where('account_id', $account->id)
                ->first();
        }

        return $query
            ->whereNull('account_id')
            ->where('participation_key_hash', $participationKeyHash)
            ->first();
    }

    private function reject(): never
    {
        throw new CannotUnclaimOpenParticipationOption(
            'This Option Claim cannot be unclaimed.',
        );
    }
}
