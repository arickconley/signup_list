<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Sheet;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

class OwnerPrintViewController extends Controller
{
    public function __invoke(Request $request, Sheet $sheet): View
    {
        $account = $request->user();

        abort_unless(
            $account instanceof Account && $sheet->owner_id === $account->id,
            404,
        );

        $grouping = $request->query('group') === 'option' ? 'option' : 'participant';
        $showEmail = $request->boolean('email');
        $showPhone = $request->boolean('phone');
        $signupColumns = ['id', 'sheet_id', 'name_snapshot'];

        if ($showEmail) {
            $signupColumns[] = 'email_snapshot';
        }

        if ($showPhone) {
            $signupColumns[] = 'phone_snapshot';
        }

        $capacityOptions = $sheet->options()
            ->select(['id', 'sheet_id', 'name', 'capacity', 'position'])
            ->withCount('optionClaims')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($grouping === 'participant') {
            $signups = $sheet->signups()
                ->select($signupColumns)
                ->with('optionClaims.option')
                ->oldest('created_at')
                ->oldest('id')
                ->get();
            $options = null;
        } else {
            $signups = null;
            $options = $sheet->options()
                ->with([
                    'optionClaims' => function (Relation $relation): void {
                        $relation->getQuery()
                            ->oldest('created_at')
                            ->oldest('id');
                    },
                    'optionClaims.signup' => function (Relation $relation) use ($signupColumns): void {
                        $relation->getQuery()->select($signupColumns);
                    },
                ])
                ->orderBy('position')
                ->orderBy('id')
                ->get();
        }

        return view('sheets.print', [
            'sheet' => $sheet,
            'grouping' => $grouping,
            'showEmail' => $showEmail,
            'showPhone' => $showPhone,
            'capacityOptions' => $capacityOptions,
            'signups' => $signups,
            'options' => $options,
        ]);
    }
}
