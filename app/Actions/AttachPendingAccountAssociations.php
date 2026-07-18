<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\PendingAccountAssociation;
use App\Models\Signup;
use App\Support\ImmediateDatabaseTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

final class AttachPendingAccountAssociations
{
    public function __construct(
        private readonly ImmediateDatabaseTransaction $immediateTransaction,
    ) {}

    public function handle(Account $account): void
    {
        $this->immediateTransaction->run(function () use ($account): void {
            $currentAccount = Account::query()->whereKey($account->getKey())->first();

            if ($currentAccount === null || ! $currentAccount->hasVerifiedEmail()) {
                return;
            }

            $normalizedEmail = Account::normalizeEmail($currentAccount->email);

            $associations = $currentAccount->pendingAccountAssociations()
                ->whereHas('signup', function (Builder $query) use ($normalizedEmail): void {
                    $query
                        ->whereNull('account_id')
                        ->where('email_snapshot', $normalizedEmail);
                })
                ->with('signup')
                ->orderBy('id')
                ->get();

            foreach ($associations as $association) {
                $this->attach($association, $currentAccount, $normalizedEmail);
            }
        });
    }

    private function attach(
        PendingAccountAssociation $association,
        Account $account,
        string $normalizedEmail,
    ): void {
        $signup = $association->signup;

        if (Signup::query()
            ->where('account_id', $account->id)
            ->where('sheet_id', $signup->sheet_id)
            ->whereKeyNot($signup->id)
            ->exists()) {
            return;
        }

        try {
            $attached = Signup::query()
                ->whereKey($signup->id)
                ->whereNull('account_id')
                ->where('email_snapshot', $normalizedEmail)
                ->update(['account_id' => $account->id]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        if ($attached === 1) {
            $association->delete();
        }
    }
}
