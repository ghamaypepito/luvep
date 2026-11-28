<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Emails a tenant the link to review and e-sign their Exalt flat/house-sharing
 * agreement. The link carries the agreement's signing token (bearer credential).
 */
class AgreementSigningLink extends Mailable
{
    use Queueable, SerializesModels, TracksAccommodationAgreementEmail;

    public function __construct(
        public string $clientName,
        public string $agreementLabel,
        public string $signUrl,
    ) {}

    public function build()
    {
        return $this->subject('Your Exalt tenancy agreement — ready to sign')
            ->view('emails.agreement-signing-link');
    }
}
