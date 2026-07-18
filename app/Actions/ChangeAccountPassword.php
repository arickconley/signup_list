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

        $this->change($account, $password, $change);
    }

    public function remove(Account $account): void
    {
        if (! $account->hasVerifiedEmail()) {
            throw new AuthorizationException;
        }

        $this->change($account, null, PasswordCredentialChange::Removed);
    }

    public function reset(Account $account, string $password): void
    {
        $this->change($account, $password, PasswordCredentialChange::Reset);
    }

    private function change(Account $account, ?string $password, PasswordCredentialChange $change): void
    {
        $account->forceFill(['password' => $password])->save();
        $account->notify(new AccountPasswordChanged($change));
    }
}
