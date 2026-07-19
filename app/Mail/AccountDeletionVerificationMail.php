<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class AccountDeletionVerificationMail extends Mailable implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly CarbonInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Confirm your Signup Sheets account deletion'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account-deletion-verification');
    }
}
