<?php

namespace App\Actions;

use App\Exceptions\CannotRemoveOwnerOptionClaim;
use App\Exceptions\ImmediateTransactionBusy;
use App\Mail\OwnerChangedSignupMail;
use App\Models\Account;
use App\Models\OptionClaim;
use App\Models\Sheet;
use App\Models\Signup;
use App\Support\ImmediateDatabaseTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class RemoveOwnerOptionClaim
{
    public function __construct(
        private readonly ImmediateDatabaseTransaction $immediateTransaction,
        private readonly ReplaceSignupClaims $replaceSignupClaims,
    ) {}

    public function handle(Account $owner, Sheet $sheet, int $optionClaimId): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new CannotRemoveOwnerOptionClaim('This Option Claim cannot be removed right now. Please try again.');
        }

        try {
            $change = $this->immediateTransaction->run(function () use ($owner, $sheet, $optionClaimId): ?array {
                $currentOwner = Account::query()->whereKey($owner->id)->first();
                $currentSheet = Sheet::query()
                    ->whereKey($sheet->id)
                    ->where('owner_id', $owner->id)
                    ->first();
                $optionClaim = OptionClaim::query()
                    ->with(['option', 'signup'])
                    ->whereKey($optionClaimId)
                    ->first();

                if (
                    $currentOwner === null
                    || $currentSheet === null
                    || $optionClaim === null
                    || $optionClaim->signup->sheet_id !== $currentSheet->id
                    || $optionClaim->option->sheet_id !== $currentSheet->id
                ) {
                    throw new CannotRemoveOwnerOptionClaim('This Option Claim cannot be removed.');
                }

                $beforeSelectionNames = $this->selectionNames($optionClaim->signup);
                $this->replaceSignupClaims->releaseOne($optionClaim);

                if ($optionClaim->signup->email_snapshot === null) {
                    return null;
                }

                return [
                    'email' => $optionClaim->signup->email_snapshot,
                    'sheet_title' => $currentSheet->title,
                    'sheet_url' => route('sheets.show', $currentSheet),
                    'before_selection_names' => $beforeSelectionNames,
                    'after_selection_names' => $this->selectionNames($optionClaim->signup),
                ];
            });
        } catch (ImmediateTransactionBusy $exception) {
            throw new CannotRemoveOwnerOptionClaim(
                'The Signup Sheet is busy. Please wait a moment and try again.',
                $exception,
            );
        }

        Log::info('signup.owner_removal', [
            'operation' => 'option_claim',
            'sheet_id' => $sheet->id,
            'removed_signups' => 0,
            'removed_option_claims' => 1,
            'notification_jobs' => $change === null ? 0 : 1,
        ]);

        if ($change !== null) {
            Mail::to($change['email'])->queue(new OwnerChangedSignupMail(
                sheetTitle: $change['sheet_title'],
                sheetUrl: $change['sheet_url'],
                beforeSelectionNames: $change['before_selection_names'],
                afterSelectionNames: $change['after_selection_names'],
            ));
        }
    }

    /** @return list<string> */
    private function selectionNames(Signup $signup): array
    {
        return array_values($signup->optionClaims()
            ->with('option')
            ->get()
            ->sortBy(fn (OptionClaim $claim): array => [
                $claim->option->position,
                $claim->option->id,
            ])
            ->map(fn (OptionClaim $claim): string => $claim->option->name)
            ->all());
    }
}
