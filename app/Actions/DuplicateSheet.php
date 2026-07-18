<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\Sheet;
use App\Support\DefaultSheetDeadline;
use App\Support\OwnerEligibility;
use Illuminate\Support\Facades\DB;

final class DuplicateSheet
{
    public function __construct(
        private readonly DefaultSheetDeadline $defaultDeadline,
        private readonly OwnerEligibility $ownerEligibility,
    ) {}

    public function handle(Account $owner, Sheet $source): Sheet
    {
        abort_unless($source->owner_id === $owner->id, 404);
        abort_unless($this->ownerEligibility->canCreateSheet($owner), 403);

        $timezone = $owner->timezone;

        abort_unless(is_string($timezone), 403);

        return DB::transaction(function () use ($owner, $source, $timezone): Sheet {
            $duplicate = $owner->ownedSheets()->create([
                'title' => $source->title,
                'description' => $source->description,
                'event_at' => $source->event_at,
                'location' => $source->location,
                'deadline_at' => $this->defaultDeadline->forTimezone($timezone),
                'timezone' => $timezone,
                'state' => Sheet::STATE_DRAFT,
                'participation_policy' => $source->participation_policy,
                'selection_maximum' => $source->selection_maximum,
                'name_visibility' => $source->name_visibility,
                'email_visibility' => $source->email_visibility,
                'phone_visibility' => $source->phone_visibility,
            ]);

            $options = $source->options()
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            foreach ($options as $option) {
                $duplicate->options()->create([
                    'name' => $option->name,
                    'description' => $option->description,
                    'capacity' => $option->capacity,
                    'position' => $option->position,
                ]);
            }

            return $duplicate;
        });
    }
}
