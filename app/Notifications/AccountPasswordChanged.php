<?php

namespace App\Notifications;

use App\Enums\PasswordCredentialChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountPasswordChanged extends Notification implements ShouldBeEncrypted, ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(public readonly PasswordCredentialChange $change) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your Signup Sheets password changed'))
            ->line(__('The password for your Account was :change.', ['change' => $this->change->value]))
            ->line(__('If you did not make this change, request a password reset and secure your email address.'));
    }
}
