<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class RequestAccountAccess extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $account = Auth::user();
        $email = $account instanceof Account
            ? $account->email
            : (string) $this->input('email');

        $this->merge([
            'email' => Account::normalizeEmail($email),
        ]);
    }
}
