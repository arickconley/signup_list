<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $sheet_id
 * @property int|null $account_id
 * @property string $name_snapshot
 * @property string|null $email_snapshot
 * @property string|null $phone_snapshot
 * @property bool $name_consent
 * @property bool $email_consent
 * @property bool $phone_consent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name_snapshot',
    'email_snapshot',
    'phone_snapshot',
    'name_consent',
    'email_consent',
    'phone_consent',
])]
class Signup extends Model
{
    /** @return BelongsTo<Sheet, $this> */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(Sheet::class);
    }

    /** @return HasMany<OptionClaim, $this> */
    public function optionClaims(): HasMany
    {
        return $this->hasMany(OptionClaim::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return HasOne<PendingAccountAssociation, $this> */
    public function pendingAccountAssociation(): HasOne
    {
        return $this->hasOne(PendingAccountAssociation::class);
    }

    public function canBeEditedBy(Account $account): bool
    {
        if (
            ! $account->hasVerifiedEmail()
            || $this->account_id !== $account->id
            || $this->pendingAccountAssociation()->exists()
        ) {
            return false;
        }

        return $this->sheet->isAcceptingParticipantEdits();
    }

    public function canBeCancelledBy(Account $account): bool
    {
        return $this->canBeEditedBy($account);
    }

    public function initials(): string
    {
        $initials = '';

        foreach (preg_split('/\s+/u', trim($this->name_snapshot)) ?: [] as $namePart) {
            $initials .= Str::upper(Str::substr($namePart, 0, 1));
        }

        return $initials;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'name_consent' => 'boolean',
            'email_consent' => 'boolean',
            'phone_consent' => 'boolean',
        ];
    }
}
