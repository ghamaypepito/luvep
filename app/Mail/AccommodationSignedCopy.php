<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccommodationSignedCopy extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $clientName, public string $pdfPath) {}

    public function build()
    {
        return $this->subject('Your signed Exalt tenancy agreement')
            ->view('emails.accommodation-signed-copy')
            ->attachFromStorageDisk('local', $this->pdfPath, 'signed-exalt-agreement.pdf', ['mime' => 'application/pdf']);
    }
}
