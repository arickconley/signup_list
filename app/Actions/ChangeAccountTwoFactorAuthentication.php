<?php

namespace App\Actions;

use App\Enums\TwoFactorAuthenticationChange;
use App\Models\Account;
use App\Notifications\AccountTwoFactorAuthenticationChanged;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

class ChangeAccountTwoFactorAuthentication
{
    public function __construct(
        private ConfirmTwoFactorAuthentication $confirmTwoFactorAuthentication,
        private DisableTwoFactorAuthentication $disableTwoFactorAuthentication,
        private GenerateNewRecoveryCodes $generateNewRecoveryCodes,
    ) {}

    /** @return list<string> */
    public function confirm(Account $account, string $code): array
    {
        if ($account->two_factor_confirmed_at !== null) {
            throw ValidationException::withMessages([
                'code' => __('Two-factor authentication is already enabled.'),
            ]);
        }

        ($this->confirmTwoFactorAuthentication)($account, $code);
        $account->notify(new AccountTwoFactorAuthenticationChanged(TwoFactorAuthenticationChange::Enabled));

        return array_values($account->fresh()->recoveryCodes());
    }

    public function disable(Account $account): void
    {
        ($this->disableTwoFactorAuthentication)($account);
        $account->notify(new AccountTwoFactorAuthenticationChanged(TwoFactorAuthenticationChange::Disabled));
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(Account $account): array
    {
        ($this->generateNewRecoveryCodes)($account);
        $account->notify(new AccountTwoFactorAuthenticationChanged(
            TwoFactorAuthenticationChange::RecoveryCodesRegenerated,
        ));

        return array_values($account->fresh()->recoveryCodes());
    }
}
