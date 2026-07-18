<?php

namespace App\Actions;

use App\Enums\PasswordCredentialChange;
use App\Models\Account;
use App\Notifications\AccountPasswordChanged;
use Illuminate\Auth\Access\AuthorizationException;

class ChangeAccountPassword
{
    public function set(Account $account, string $password): void
    {
        if (! $account->hasVerifiedEmail()) {
            throw new AuthorizationException;
        }

        $change = $account->password === null
            ? PasswordCredentialChange::Added
            : PasswordCredentialChange::Replaced;

        $account->forceFill(['password' => $password])->save();
        $account->notify(new AccountPasswordChanged($change));
    }

    public function remove(Account $account): void
    {
        if (! $account->hasVerifiedEmail()) {
            throw new AuthorizationException;
        }

        $account->forceFill(['password' => null])->save();
        $account->notify(new AccountPasswordChanged(PasswordCredentialChange::Removed));
    }

    public function reset(Account $account, string $password): void
    {
        $account->forceFill(['password' => $password])->save();
        $account->notify(new AccountPasswordChanged(PasswordCredentialChange::Reset));
    }
}
