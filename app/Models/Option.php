<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $sheet_id
 * @property string $name
 * @property string|null $description
 * @property int $capacity
 * @property int $claimed_count
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'capacity', 'claimed_count', 'position'])]
class Option extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Option $option): void {
            $option->public_id ??= (string) Str::uuid();
        });
    }

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'claimed_count' => 'integer',
            'position' => 'integer',
        ];
    }
}
