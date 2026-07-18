<?php

namespace App\Concerns;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

final class ProfileValidationRules
{
    /**
     * Get the validation rules used to validate account profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public static function profile(?int $accountId = null): array
    {
        return [
            'name' => self::name(),
            'email' => self::email($accountId),
            'phone' => ['nullable', 'string', 'max:30'],
            'timezone' => ['required', 'string', 'timezone:all'],
        ];
    }

    /**
     * Get the validation rules used to validate account names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    private static function name(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate account emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    private static function email(?int $accountId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $accountId === null
                ? Rule::unique(Account::class)
                : Rule::unique(Account::class)->ignore($accountId),
        ];
    }
}
