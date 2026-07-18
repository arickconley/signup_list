<?php

namespace App\Support;

use Illuminate\Support\Facades\Date;

final class FreshAuthentication
{
    public function ensure(): void
    {
        $confirmedAt = (int) session()->get('auth.password_confirmed_at', 0);
        $timeout = (int) config('auth.password_timeout', 10800);

        abort_if(
            Date::now()->unix() - $confirmedAt > $timeout,
            423,
            __('Fresh authentication required.'),
        );
    }
}
