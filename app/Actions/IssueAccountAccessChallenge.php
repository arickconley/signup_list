<?php

namespace App\Actions;

use App\Mail\AccountAccessMail;
use App\Models\Account;
use App\Models\AccountAccessChallenge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class IssueAccountAccessChallenge
{
    public function handle(string $email): AccountAccessChallenge
    {
        $email = Account::normalizeEmail($email);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token = Str::random(64);
        $expiresAt = now()->addMinutes(config('account-access.lifetime_minutes'));

        $challenge = DB::transaction(function () use ($email, $code, $token, $expiresAt): AccountAccessChallenge {
            AccountAccessChallenge::query()
                ->where('email', $email)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            return AccountAccessChallenge::query()->create([
                'public_id' => (string) Str::uuid(),
                'email' => $email,
                'code_hash' => Hash::make($code),
                'token_hash' => Hash::make($token),
                'expires_at' => $expiresAt,
            ]);
        });

        $magicLink = URL::temporarySignedRoute(
            'account-access.magic',
            $expiresAt,
            ['challenge' => $challenge->public_id, 'token' => $token],
        );

        Mail::to($email)->queue(new AccountAccessMail($code, $magicLink, $expiresAt));

        return $challenge;
    }
}
