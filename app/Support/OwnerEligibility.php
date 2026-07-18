<?php

namespace App\Support;

use App\Models\Account;
use Illuminate\Support\Str;

final class OwnerEligibility
{
    public function canCreateSheet(Account $account): bool
    {
        return $account->hasVerifiedEmail()
            && ! $this->usesDisposableEmailDomain($account->email);
    }

    private function usesDisposableEmailDomain(string $email): bool
    {
        $domain = $this->normalizeDomain(Str::afterLast($email, '@'));
        $blockedDomains = config('disposable-email-domains', []);

        if (! is_array($blockedDomains)) {
            return false;
        }

        foreach ($blockedDomains as $blockedDomain) {
            if (! is_string($blockedDomain)) {
                continue;
            }

            $blockedDomain = $this->normalizeDomain($blockedDomain);

            if ($domain === $blockedDomain || str_ends_with($domain, '.'.$blockedDomain)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeDomain(string $domain): string
    {
        return rtrim(Str::lower(trim($domain)), '.');
    }
}
