<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Sheet;
use App\Models\Signup;
use App\Support\OpenParticipationIdentity;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ShowPublishedSheetController extends Controller
{
    public function __invoke(
        Sheet $sheet,
        OpenParticipationIdentity $participationIdentity,
    ): Response {
        if (! $sheet->isPubliclyViewable()) {
            return response()
                ->view('sheets.unavailable', status: 404)
                ->header('X-Robots-Tag', 'noindex, nofollow');
        }

        $participantSignup = null;

        if ($sheet->participation_policy === Sheet::PARTICIPATION_OPEN) {
            $authenticatedAccount = Auth::user();

            $participantSignup = Signup::query()
                ->where('sheet_id', $sheet->id)
                ->when(
                    $authenticatedAccount instanceof Account,
                    fn ($query) => $query->where('account_id', $authenticatedAccount->id),
                    fn ($query) => $query->where(
                        'participation_key_hash',
                        $participationIdentity->hashForSheet($sheet->public_id),
                    ),
                )
                ->with('optionClaims')
                ->first();
        }

        $participantClaimedOptionIds = $participantSignup?->optionClaims
            ->pluck('option_id')
            ->all() ?? [];

        return response()
            ->view('sheets.show', [
                'sheet' => $sheet,
                'isOpen' => $sheet->isOpen(),
                'participantClaimedOptionIds' => $participantClaimedOptionIds,
                'participantReachedSelectionMaximum' => count($participantClaimedOptionIds) >= $sheet->selection_maximum,
                'options' => $sheet->options()
                    ->with('optionClaims.signup')
                    ->orderBy('position')
                    ->get(['id', 'public_id', 'name', 'description', 'capacity', 'claimed_count', 'position']),
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
