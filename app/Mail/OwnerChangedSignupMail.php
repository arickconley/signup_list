<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class OwnerChangedSignupMail extends Mailable implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * @param  list<string>  $beforeSelectionNames
     * @param  list<string>  $afterSelectionNames
     */
    public function __construct(
        public readonly string $sheetTitle,
        public readonly string $sheetUrl,
        public readonly array $beforeSelectionNames,
        public readonly array $afterSelectionNames,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('The Owner changed your Signup'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.owner-changed-signup');
    }
}
