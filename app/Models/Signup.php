<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sheet_id
 * @property string $name_snapshot
 * @property string|null $phone_snapshot
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name_snapshot', 'phone_snapshot'])]
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
}
