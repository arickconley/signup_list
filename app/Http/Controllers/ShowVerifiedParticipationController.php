<?php

namespace App\Http\Controllers;

use App\Models\Sheet;
use Illuminate\Http\Response;

class ShowVerifiedParticipationController extends Controller
{
    public function __invoke(Sheet $sheet): Response
    {
        if (! $sheet->isAcceptingVerifiedParticipationSignups()) {
            return response()
                ->view('sheets.unavailable', status: 404)
                ->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return response()
            ->view('sheets.participate', ['sheet' => $sheet])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
