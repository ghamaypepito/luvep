<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccommodationViewingBooked extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $name,
        public string $address,
        public string $date,
        public string $time,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Exalt property viewing confirmed');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.accommodation-viewing-booked');
    }
}
