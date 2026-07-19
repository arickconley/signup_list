<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Sheet;
use App\Support\OwnerEligibility;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, OwnerEligibility $ownerEligibility): View
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 403);

        $ownedSheets = $account->ownedSheets()
            ->latest()
            ->get();

        return view('dashboard', [
            'drafts' => $ownedSheets->where('state', Sheet::STATE_DRAFT),
            'openSheets' => $ownedSheets->filter->isOpen(),
            'closedSheets' => $ownedSheets->filter->isClosed(),
            'archivedSheets' => $ownedSheets->where('state', Sheet::STATE_ARCHIVED),
            'canCreateSheets' => $ownerEligibility->canCreateSheet($account),
            'attachedSignups' => $account->signups()
                ->whereHas('sheet', function (Builder $query): void {
                    $query->where('state', '!=', Sheet::STATE_ARCHIVED);
                })
                ->with(['sheet', 'optionClaims.option'])
                ->latest('created_at')
                ->latest('id')
                ->get(),
        ]);
    }
}
