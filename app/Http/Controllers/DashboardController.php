<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 403);

        return view('dashboard', [
            'drafts' => $account->ownedSheets()
                ->where('state', Sheet::STATE_DRAFT)
                ->latest()
                ->get(),
            'archivedSheets' => $account->ownedSheets()
                ->where('state', Sheet::STATE_ARCHIVED)
                ->latest()
                ->get(),
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
