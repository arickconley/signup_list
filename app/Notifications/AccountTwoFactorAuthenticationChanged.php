<?php

namespace App\Notifications;

use App\Enums\TwoFactorAuthenticationChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountTwoFactorAuthenticationChanged extends Notification implements ShouldBeEncrypted, ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(public readonly TwoFactorAuthenticationChange $change) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your Signup Sheets two-factor authentication changed'))
            ->line(__('Two-factor authentication for your Account was :change.', ['change' => $this->change->value]))
            ->line(__('If you did not make this change, secure your email address and review your Account sign-in methods.'));
    }
}
