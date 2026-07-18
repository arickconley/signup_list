<?php

namespace App\Support;

use App\Models\Account;
use Illuminate\Cache\RateLimiter;

final class AccountAccessAbuseControl
{
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    public function __construct(private readonly RateLimiter $rateLimiter) {}

    public function attemptSend(string $email, string $ipAddress): bool
    {
        $emailDigest = $this->digest(Account::normalizeEmail($email));
        $ipDigest = $this->digest($ipAddress);

        return $this->attempt([
            [
                'key' => 'account-access:send:email:'.$emailDigest,
                'max' => (int) config('account-access.send_limit_per_email'),
                'decay' => self::RATE_LIMIT_WINDOW_SECONDS,
            ],
            [
                'key' => 'account-access:send:ip:'.$ipDigest,
                'max' => (int) config('account-access.send_limit_per_ip'),
                'decay' => self::RATE_LIMIT_WINDOW_SECONDS,
            ],
            [
                'key' => 'account-access:send:cooldown:'.$emailDigest,
                'max' => 1,
                'decay' => (int) config('account-access.resend_cooldown_seconds'),
            ],
        ]);
    }

    public function attemptVerification(string $email, string $ipAddress): bool
    {
        $emailDigest = $this->digest(Account::normalizeEmail($email));

        $ipDigest = $this->digest($ipAddress);

        return $this->attempt([
            [
                'key' => 'account-access:verification:email:'.$emailDigest,
                'max' => (int) config('account-access.verification_limit_per_email'),
                'decay' => self::RATE_LIMIT_WINDOW_SECONDS,
            ],
            [
                'key' => 'account-access:verification:ip:'.$ipDigest,
                'max' => (int) config('account-access.verification_limit_per_ip'),
                'decay' => self::RATE_LIMIT_WINDOW_SECONDS,
            ],
        ]);
    }

    private function digest(string $identifier): string
    {
        return hash_hmac('sha256', $identifier, (string) config('app.key'));
    }

    /**
     * @param  list<array{key: string, max: int, decay: int}>  $buckets
     */
    private function attempt(array $buckets): bool
    {
        foreach ($buckets as $bucket) {
            if ($this->rateLimiter->tooManyAttempts($bucket['key'], $bucket['max'])) {
                return false;
            }
        }

        foreach ($buckets as $bucket) {
            $this->rateLimiter->hit($bucket['key'], $bucket['decay']);
        }

        return true;
    }
}
