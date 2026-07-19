<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class ProductionSmokeMail extends Mailable implements ShouldQueueAfterCommit
{
    use Queueable;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Signup production smoke check');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Production smoke check.</p>');
    }
}
