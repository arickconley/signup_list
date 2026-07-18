<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $email
 * @property string $code_hash
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
#[Fillable(['public_id', 'email', 'code_hash', 'token_hash', 'expires_at', 'used_at'])]
class AccountAccessChallenge extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }
}
