<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class DefaultSheetDeadline
{
    public function forTimezone(string $timezone): Carbon
    {
        return Carbon::now($timezone)
            ->addDays(14)
            ->setTime(23, 59)
            ->utc();
    }
}
