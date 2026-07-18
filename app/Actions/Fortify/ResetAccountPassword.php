<?php

namespace App\Actions\Fortify;

use App\Actions\ChangeAccountPassword;
use App\Concerns\PasswordValidationRules;
use App\Models\Account;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetAccountPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private readonly ChangeAccountPassword $changeAccountPassword) {}

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

        $this->changeAccountPassword->reset($account, $input['password']);
    }
}
