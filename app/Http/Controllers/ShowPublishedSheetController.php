<?php

namespace App\Http\Controllers;

use App\Models\Sheet;
use Illuminate\Http\Response;

class ShowPublishedSheetController extends Controller
{
    public function __invoke(Sheet $sheet): Response
    {
        if ($sheet->state !== Sheet::STATE_PUBLISHED) {
            return response()
                ->view('sheets.unavailable', status: 404)
                ->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return response()
            ->view('sheets.show', [
                'sheet' => $sheet,
                'isOpen' => $sheet->deadline_at->isFuture(),
                'options' => $sheet->options()
                    ->with('optionClaims.signup')
                    ->orderBy('position')
                    ->get(['id', 'name', 'description', 'capacity', 'claimed_count', 'position']),
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
