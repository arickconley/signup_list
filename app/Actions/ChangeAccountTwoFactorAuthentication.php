<?php

namespace App\Actions;

use App\Enums\TwoFactorCredentialChange;
use App\Models\Account;
use App\Notifications\AccountTwoFactorAuthenticationChanged;
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
        ($this->confirmTwoFactorAuthentication)($account, $code);
        $account->notify(new AccountTwoFactorAuthenticationChanged(TwoFactorCredentialChange::Enabled));

        return array_values($account->fresh()->recoveryCodes());
    }

    public function disable(Account $account): void
    {
        ($this->disableTwoFactorAuthentication)($account);
        $account->notify(new AccountTwoFactorAuthenticationChanged(TwoFactorCredentialChange::Disabled));
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(Account $account): array
    {
        ($this->generateNewRecoveryCodes)($account);
        $account->notify(new AccountTwoFactorAuthenticationChanged(
            TwoFactorCredentialChange::RecoveryCodesRegenerated,
        ));

        return array_values($account->fresh()->recoveryCodes());
    }
}
