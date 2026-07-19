<?php

namespace App\Actions;

use App\Exceptions\CannotRemoveOwnerSignup;
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

final class RemoveOwnerSignup
{
    public function __construct(
        private readonly ImmediateDatabaseTransaction $immediateTransaction,
        private readonly ReplaceSignupClaims $replaceSignupClaims,
    ) {}

    public function handle(Account $owner, Sheet $sheet, int $signupId): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new CannotRemoveOwnerSignup('This Signup cannot be removed right now. Please try again.');
        }

        try {
            $change = $this->immediateTransaction->run(function () use ($owner, $sheet, $signupId): array {
                $currentOwner = Account::query()->whereKey($owner->id)->first();
                $currentSheet = Sheet::query()
                    ->whereKey($sheet->id)
                    ->where('owner_id', $owner->id)
                    ->first();
                $signup = Signup::query()
                    ->whereKey($signupId)
                    ->first();

                if (
                    $currentOwner === null
                    || $currentSheet === null
                    || $signup === null
                    || $signup->sheet_id !== $currentSheet->id
                ) {
                    throw new CannotRemoveOwnerSignup('This Signup cannot be removed.');
                }

                $beforeSelectionNames = $this->selectionNames($signup);
                $this->replaceSignupClaims->releaseAll($signup);
                $signup->delete();

                return [
                    'email' => $signup->email_snapshot,
                    'sheet_title' => $currentSheet->title,
                    'sheet_url' => route('sheets.show', $currentSheet),
                    'before_selection_names' => $beforeSelectionNames,
                    'removed_option_claims' => count($beforeSelectionNames),
                ];
            });
        } catch (ImmediateTransactionBusy $exception) {
            throw new CannotRemoveOwnerSignup(
                'The Signup Sheet is busy. Please wait a moment and try again.',
                $exception,
            );
        }

        Log::info('signup.owner_removal', [
            'operation' => 'signup',
            'sheet_id' => $sheet->id,
            'removed_signups' => 1,
            'removed_option_claims' => $change['removed_option_claims'],
            'notification_jobs' => $change['email'] === null ? 0 : 1,
        ]);

        if ($change['email'] !== null) {
            Mail::to($change['email'])->queue(new OwnerChangedSignupMail(
                sheetTitle: $change['sheet_title'],
                sheetUrl: $change['sheet_url'],
                beforeSelectionNames: $change['before_selection_names'],
                afterSelectionNames: [],
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
