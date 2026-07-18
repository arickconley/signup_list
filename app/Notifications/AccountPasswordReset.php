<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

class AccountPasswordReset extends ResetPassword implements ShouldBeEncrypted, ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;
}
