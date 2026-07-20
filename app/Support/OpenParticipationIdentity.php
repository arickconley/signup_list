<?php

namespace App\Support;

use Illuminate\Support\Str;

final class OpenParticipationIdentity
{
    public function hashForSheet(string $sheetPublicId): string
    {
        $sessionKey = 'open-participation.identities.'.$sheetPublicId;
        $identity = session()->get($sessionKey);

        if (! is_string($identity) || $identity === '') {
            $identity = (string) Str::uuid();
            session()->put($sessionKey, $identity);
        }

        return hash_hmac('sha256', $identity, (string) config('app.key'));
    }
}
