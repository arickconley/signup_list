<?php

namespace App\Models;

use Database\Factories\SheetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $public_id
 * @property string $title
 * @property string|null $description
 * @property Carbon|null $event_at
 * @property string|null $location
 * @property Carbon $deadline_at
 * @property string $timezone
 * @property string $state
 * @property string $participation_policy
 * @property int|null $selection_maximum
 * @property string $name_visibility
 * @property string $email_visibility
 * @property string $phone_visibility
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'title',
    'description',
    'event_at',
    'location',
    'deadline_at',
    'timezone',
    'state',
    'participation_policy',
    'selection_maximum',
    'name_visibility',
    'email_visibility',
    'phone_visibility',
])]
class Sheet extends Model
{
    /** @use HasFactory<SheetFactory> */
    use HasFactory;

    public const string STATE_DRAFT = 'draft';

    public const string STATE_PUBLISHED = 'published';

    public const string STATE_ARCHIVED = 'archived';

    public const string PARTICIPATION_OPEN = 'open';

    public const string VISIBILITY_OWNER_ONLY = 'owner_only';

    protected static function booted(): void
    {
        static::creating(function (Sheet $sheet): void {
            $sheet->public_id ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<Account, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'owner_id');
    }

    /** @return HasMany<Option, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(Option::class);
    }

    /** @return HasMany<Signup, $this> */
    public function signups(): HasMany
    {
        return $this->hasMany(Signup::class);
    }

    /** @param  Builder<Sheet>  $query */
    public function scopeNotArchived(Builder $query): void
    {
        $query->where('state', '!=', self::STATE_ARCHIVED);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'deadline_at' => 'datetime',
            'selection_maximum' => 'integer',
        ];
    }
}
