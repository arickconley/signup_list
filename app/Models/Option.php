<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
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
    /** @return BelongsTo<Sheet, $this> */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(Sheet::class);
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
