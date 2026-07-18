<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $signup_id
 * @property int $option_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['option_id'])]
class OptionClaim extends Model
{
    /** @return BelongsTo<Signup, $this> */
    public function signup(): BelongsTo
    {
        return $this->belongsTo(Signup::class);
    }

    /** @return BelongsTo<Option, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class);
    }
}
