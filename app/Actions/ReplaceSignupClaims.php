<?php

namespace App\Actions;

use App\Data\PreparedSignupSelection;
use App\Data\SignupClaimTarget;
use App\Exceptions\CannotChangeSignupClaims;
use App\Models\Option;
use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ReplaceSignupClaims
{
    /**
     * Validate a new Signup's selection before resolving duplicate identity, then apply it only
     * to a newly created Signup. The validated selection never escapes this operation.
     *
     * @param  array<int, string>  $optionPublicIds
     * @param  Closure(): SignupClaimTarget  $resolveTarget
     */
    public function forNewSignup(
        Sheet $sheet,
        array $optionPublicIds,
        Closure $resolveTarget,
    ): SignupClaimTarget {
        $this->assertInsideTransaction();

        /** @var Collection<int, OptionClaim> $currentClaims */
        $currentClaims = new Collection;
        $selection = $this->prepare($sheet, $currentClaims, $optionPublicIds);
        $target = $resolveTarget();

        if (! $target->alreadyComplete) {
            $this->apply($target->signup, $selection);
        }

        return $target;
    }

    /**
     * @param  array<int, string>  $optionPublicIds
     */
    public function handle(
        Sheet $sheet,
        Signup $signup,
        array $optionPublicIds,
        bool $allowExistingOverLimit = false,
    ): void {
        $this->assertInsideTransaction();

        $currentClaims = $signup->optionClaims()
            ->with('option')
            ->orderBy('option_id')
            ->get();
        $selection = $this->prepare(
            $sheet,
            $currentClaims,
            $optionPublicIds,
            $allowExistingOverLimit,
        );

        $this->apply($signup, $selection);
    }

    public function releaseAll(Signup $signup): void
    {
        $this->assertInsideTransaction();

        $claims = $signup->optionClaims()
            ->orderBy('option_id')
            ->get();

        $this->remove($claims->all());
    }

    /**
     * @param  Collection<int, OptionClaim>  $currentClaims
     * @param  array<int, string>  $optionPublicIds
     */
    private function prepare(
        Sheet $sheet,
        Collection $currentClaims,
        array $optionPublicIds,
        bool $allowExistingOverLimit = false,
    ): PreparedSignupSelection {
        if (count(array_unique($optionPublicIds)) !== count($optionPublicIds)) {
            throw new CannotChangeSignupClaims('Choose each Option only once.');
        }

        $options = Option::query()
            ->where('sheet_id', $sheet->id)
            ->whereIn('public_id', $optionPublicIds)
            ->orderBy('id')
            ->get();

        if ($options->count() !== count($optionPublicIds)) {
            throw new CannotChangeSignupClaims(
                'One or more selected Options do not belong to this Signup Sheet.',
            );
        }

        $selectionMaximum = $sheet->selection_maximum;
        $currentOptionPublicIds = $currentClaims
            ->map(fn (OptionClaim $claim): string => $claim->option->public_id)
            ->all();
        $addedPublicIds = array_values(array_diff($optionPublicIds, $currentOptionPublicIds));
        $currentIsOverLimit = $selectionMaximum !== null
            && count($currentOptionPublicIds) > $selectionMaximum;

        if ($allowExistingOverLimit && $currentIsOverLimit && $addedPublicIds !== []) {
            throw new CannotChangeSignupClaims(
                'Remove existing Option Claims before adding another Option.',
            );
        }

        $isRemovalOnlyFromOverLimit = $allowExistingOverLimit
            && $currentIsOverLimit
            && $addedPublicIds === [];

        if (
            $selectionMaximum === null
            || count($optionPublicIds) < 1
            || (count($optionPublicIds) > $selectionMaximum && ! $isRemovalOnlyFromOverLimit)
        ) {
            throw new CannotChangeSignupClaims(
                "Choose between 1 and {$selectionMaximum} available Options.",
            );
        }

        $addedOptions = $options
            ->whereIn('public_id', $addedPublicIds)
            ->values();
        $unavailableOptions = $addedOptions->filter(
            fn (Option $option): bool => $option->claimed_count >= $option->capacity,
        );

        if ($unavailableOptions->isNotEmpty()) {
            throw new CannotChangeSignupClaims(
                'Some selected Options just became unavailable. Choose another Option and try again.',
                $unavailableOptions->pluck('name')->all(),
                $unavailableOptions->pluck('public_id')->all(),
            );
        }

        $removedClaims = $currentClaims
            ->reject(fn (OptionClaim $claim): bool => in_array(
                $claim->option->public_id,
                $optionPublicIds,
                true,
            ))
            ->values();

        return new PreparedSignupSelection(
            addedOptions: $addedOptions->all(),
            removedClaims: $removedClaims->all(),
        );
    }

    private function apply(Signup $signup, PreparedSignupSelection $selection): void
    {
        $this->remove($selection->removedClaims);

        foreach ($selection->addedOptions as $option) {
            $incremented = Option::query()
                ->whereKey($option->id)
                ->whereColumn('claimed_count', '<', 'capacity')
                ->increment('claimed_count');

            if ($incremented !== 1) {
                throw new CannotChangeSignupClaims(
                    'Some selected Options just became unavailable. Choose another Option and try again.',
                    [$option->name],
                    [$option->public_id],
                );
            }

            $signup->optionClaims()->create(['option_id' => $option->id]);
        }
    }

    /** @param array<int, OptionClaim> $claims */
    private function remove(array $claims): void
    {
        foreach ($claims as $claim) {
            $decremented = Option::query()
                ->whereKey($claim->option_id)
                ->where('claimed_count', '>', 0)
                ->decrement('claimed_count');

            if ($decremented !== 1) {
                throw new LogicException('Option claimed count is inconsistent with its claims.');
            }

            $claim->delete();
        }
    }

    private function assertInsideTransaction(): void
    {
        if (! DB::connection()->getPdo()->inTransaction()) {
            throw new LogicException('Signup claims must change inside an immediate database transaction.');
        }
    }
}
