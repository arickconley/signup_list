<?php

namespace App\Actions;

use App\Exceptions\CannotDeleteOwnerOption;
use App\Exceptions\ImmediateTransactionBusy;
use App\Mail\OwnerChangedSignupMail;
use App\Models\Account;
use App\Models\Option;
use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use App\Support\ImmediateDatabaseTransaction;
use Illuminate\Support\Facades\Mail;

final class DeleteOwnerOption
{
    public function __construct(
        private readonly ImmediateDatabaseTransaction $immediateTransaction,
    ) {}

    public function handle(Account $owner, Sheet $sheet, int $optionId, int $confirmedClaimCount): void
    {
        try {
            $ownerChangeNotifications = $this->immediateTransaction->run(function () use ($owner, $sheet, $optionId, $confirmedClaimCount): array {
                $currentOwner = Account::query()->whereKey($owner->id)->first();
                $currentSheet = Sheet::query()
                    ->whereKey($sheet->id)
                    ->where('owner_id', $owner->id)
                    ->first();
                $option = Option::query()
                    ->whereKey($optionId)
                    ->where('sheet_id', $sheet->id)
                    ->first();

                if ($currentOwner === null || $currentSheet === null || $option === null) {
                    throw new CannotDeleteOwnerOption('This Option cannot be deleted.');
                }

                if ($option->optionClaims()->count() !== $confirmedClaimCount) {
                    throw new CannotDeleteOwnerOption('This Option cannot be deleted.');
                }

                if (
                    $currentSheet->state === Sheet::STATE_PUBLISHED
                    && Option::query()->where('sheet_id', $currentSheet->id)->count() === 1
                ) {
                    throw new CannotDeleteOwnerOption('A Published Sheet must keep at least one Option.');
                }

                $affectedSignups = Signup::query()
                    ->whereIn('id', $option->optionClaims()->select('signup_id'))
                    ->whereNotNull('email_snapshot')
                    ->orderBy('id')
                    ->get();
                $ownerChangeNotifications = $affectedSignups->map(fn (Signup $signup): array => [
                    'email' => $signup->email_snapshot,
                    'sheet_title' => $currentSheet->title,
                    'sheet_url' => route('sheets.show', $currentSheet),
                    'before_selection_names' => $this->selectionNames($signup),
                    'after_selection_names' => $this->selectionNames($signup, $option->id),
                    'removed_option_name' => $option->name,
                ])->all();

                $option->optionClaims()->delete();
                $option->delete();

                $remainingOptions = Option::query()
                    ->where('sheet_id', $currentSheet->id)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get();

                foreach ($remainingOptions as $index => $remainingOption) {
                    $remainingOption->update(['position' => $index + 1]);
                }

                if (
                    $currentSheet->selection_maximum !== null
                    && $currentSheet->selection_maximum > $remainingOptions->count()
                ) {
                    $currentSheet->update([
                        'selection_maximum' => $remainingOptions->count(),
                    ]);
                }

                return $ownerChangeNotifications;
            });
        } catch (ImmediateTransactionBusy $exception) {
            throw new CannotDeleteOwnerOption(
                'The Signup Sheet is busy. Please wait a moment and try again.',
                previous: $exception,
            );
        }

        foreach ($ownerChangeNotifications as $ownerChangeNotification) {
            Mail::to($ownerChangeNotification['email'])->queue(new OwnerChangedSignupMail(
                sheetTitle: $ownerChangeNotification['sheet_title'],
                sheetUrl: $ownerChangeNotification['sheet_url'],
                beforeSelectionNames: $ownerChangeNotification['before_selection_names'],
                afterSelectionNames: $ownerChangeNotification['after_selection_names'],
                removedOptionName: $ownerChangeNotification['removed_option_name'],
            ));
        }
    }

    /** @return list<string> */
    private function selectionNames(Signup $signup, ?int $excludedOptionId = null): array
    {
        return array_values($signup->optionClaims()
            ->with('option')
            ->get()
            ->reject(fn (OptionClaim $claim): bool => $claim->option_id === $excludedOptionId)
            ->sortBy(fn (OptionClaim $claim): array => [
                $claim->option->position,
                $claim->option->id,
            ])
            ->map(fn (OptionClaim $claim): string => $claim->option->name)
            ->all());
    }
}
