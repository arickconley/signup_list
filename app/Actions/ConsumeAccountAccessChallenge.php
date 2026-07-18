<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\AccountAccessChallenge;
use App\Support\ImmediateDatabaseTransaction;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Hash;

class ConsumeAccountAccessChallenge
{
    public function __construct(
        private readonly ImmediateDatabaseTransaction $immediateTransaction,
        private readonly AttachPendingAccountAssociations $attachPendingAccountAssociations,
    ) {}

    public function usingCode(string $publicId, string $code): ?Account
    {
        return $this->consume($publicId, $code, 'code_hash');
    }

    public function usingToken(string $publicId, string $token): ?Account
    {
        return $this->consume($publicId, $token, 'token_hash');
    }

    private function consume(string $publicId, string $secret, string $hashColumn): ?Account
    {
        return $this->immediateTransaction->run(function () use ($publicId, $secret, $hashColumn): ?Account {
            $challenge = AccountAccessChallenge::query()
                ->where('public_id', $publicId)
                ->first();

            if ($challenge === null
                || $challenge->used_at !== null
                || $challenge->expires_at->isPast()
                || ! Hash::check($secret, $challenge->{$hashColumn})) {
                return null;
            }

            $consumed = AccountAccessChallenge::query()
                ->whereKey($challenge->getKey())
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['used_at' => now()]);

            if ($consumed !== 1) {
                return null;
            }

            $account = Account::query()->firstOrCreate(
                ['email' => $challenge->email],
                ['name' => null, 'password' => null],
            );

            $newlyVerified = ! $account->hasVerifiedEmail();

            if ($newlyVerified) {
                $account->markEmailAsVerified();
            }

            $this->attachPendingAccountAssociations->handle($account);

            if ($newlyVerified) {
                event(new Verified($account));
            }

            return $account;
        });
    }
}
