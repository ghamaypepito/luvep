<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

// Deliberately synchronous: the send lock spans the actual hand-off to the
// mail transport, and attachments use the reviewed bytes, never mutable paths.
class ReviewedEngagementMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $htmlBody,
        public array $files,
        public array $delivery = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->delivery['from'] ?? null,
            replyTo: $this->delivery['reply_to'] ?? [],
            cc: $this->delivery['cc'] ?? [],
            bcc: $this->delivery['bcc'] ?? [],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.raw', with: ['bodyHtml' => $this->htmlBody]);
    }

    public function attachments(): array
    {
        return array_map(fn ($file) => Attachment::fromData(
            fn () => base64_decode($file['bytes']), $file['name']
        )->withMime('application/pdf'), $this->files);
    }
}
