<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MediaDriveCode extends Mailable
{
    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Luvep Media Drive verification code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.media-drive-code');
    }
}
