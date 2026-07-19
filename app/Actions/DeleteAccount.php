<?php

namespace App\Actions;

use App\Data\AccountDeletionSummary;
use App\Exceptions\CannotDeleteAccount;
use App\Exceptions\ImmediateTransactionBusy;
use App\Models\Account;
use App\Models\AccountAccessChallenge;
use App\Models\Option;
use App\Models\OptionClaim;
use App\Models\PendingAccountAssociation;
use App\Models\Sheet;
use App\Models\Signup;
use App\Support\ImmediateDatabaseTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DeleteAccount
{
    public function __construct(
        private readonly ImmediateDatabaseTransaction $immediateTransaction,
    ) {}

    public function handle(Account $account, AccountDeletionSummary $confirmedSummary): void
    {
        try {
            $this->immediateTransaction->run(function () use ($account, $confirmedSummary): void {
                $currentAccount = Account::query()->find($account->id);

                if ($currentAccount === null) {
                    throw new CannotDeleteAccount(
                        'This Account cannot be deleted. Nothing was changed.',
                    );
                }

                if (AccountDeletionSummary::for($currentAccount)->toArray() !== $confirmedSummary->toArray()) {
                    throw new CannotDeleteAccount(
                        'Your Signup Sheet summary changed. Review it and confirm again.',
                    );
                }

                $ownedSheetIds = Sheet::query()
                    ->where('owner_id', $currentAccount->id)
                    ->select('id');

                $retainedSignupIds = Signup::query()
                    ->whereNotIn('sheet_id', clone $ownedSheetIds)
                    ->where(function ($query) use ($currentAccount): void {
                        $query->where('account_id', $currentAccount->id)
                            ->orWhereIn('id', PendingAccountAssociation::query()
                                ->where('account_id', $currentAccount->id)
                                ->select('signup_id'));
                    })
                    ->select('id');

                Signup::query()
                    ->whereIn('id', $retainedSignupIds)
                    ->update([
                        'account_id' => null,
                        'name_snapshot' => 'Deleted participant',
                        'email_snapshot' => null,
                        'phone_snapshot' => null,
                        'name_consent' => false,
                        'email_consent' => false,
                        'phone_consent' => false,
                        'updated_at' => now(),
                    ]);

                OptionClaim::query()
                    ->whereIn('signup_id', Signup::query()->whereIn('sheet_id', clone $ownedSheetIds)->select('id'))
                    ->delete();
                Signup::query()->whereIn('sheet_id', clone $ownedSheetIds)->delete();
                Option::query()->whereIn('sheet_id', clone $ownedSheetIds)->delete();
                Sheet::query()->whereIn('id', clone $ownedSheetIds)->delete();

                AccountAccessChallenge::query()
                    ->where('email', $currentAccount->email)
                    ->delete();
                DB::table('password_reset_tokens')
                    ->where('email', $currentAccount->email)
                    ->delete();
                DB::table('sessions')
                    ->where('user_id', $currentAccount->id)
                    ->delete();

                $currentAccount->delete();
            });
        } catch (ImmediateTransactionBusy $exception) {
            throw new CannotDeleteAccount(
                'Account deletion is temporarily unavailable. Please wait a moment and try again.',
                previous: $exception,
            );
        } catch (QueryException $exception) {
            throw new CannotDeleteAccount(
                'This Account cannot be deleted. Nothing was changed.',
                previous: $exception,
            );
        }
    }
}
