<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccommodationSignedCopy extends Mailable
{
    use Queueable, SerializesModels, TracksAccommodationAgreementEmail;

    public bool $forExalt = false;

    public function __construct(public string $clientName, public string $pdfPath, bool $forExalt = false)
    {
        $this->forExalt = $forExalt;
    }

    public function build()
    {
        return $this->subject($this->forExalt ? 'Completed Exalt tenancy agreement' : 'Your signed Exalt tenancy agreement')
            ->view('emails.accommodation-signed-copy')
            ->attachFromStorageDisk('local', $this->pdfPath, 'signed-exalt-agreement.pdf', ['mime' => 'application/pdf']);
    }
}
