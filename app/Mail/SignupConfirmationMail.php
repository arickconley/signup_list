<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SignupConfirmationMail extends Mailable implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * @param  list<string>  $selectionNames
     */
    public function __construct(
        public readonly string $sheetTitle,
        public readonly string $sheetUrl,
        public readonly array $selectionNames,
        public readonly string $code,
        public readonly string $magicLink,
        public readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your Signup confirmation and sign-in code'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.signup-confirmation');
    }
}
