<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\Account;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetAccountPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the account's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(Account $account, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $account->forceFill([
            'password' => $input['password'],
        ])->save();
    }
}
