<?php

namespace App\Services\Accommodation;

use App\Mail\AccommodationSignedCopy;
use App\Mail\AgreementSigningLink;
use App\Models\AccommodationAgreement;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AgreementEmail
{
    public static function exaltAddress(): ?string
    {
        $address = config('mail.accommodation_signed_copy_to');

        return filter_var((string) $address, FILTER_VALIDATE_EMAIL) ? $address : null;
    }

    public function signingLink(AccommodationAgreement $agreement, string $recipient): bool
    {
        return $this->queue($agreement, 'signing_link', $recipient, new AgreementSigningLink(
            $agreement->client_name ?: 'there', $agreement->typeLabel(), $agreement->signingUrl()
        ));
    }

    public function completedCopy(AccommodationAgreement $agreement, bool $exalt = false): bool
    {
        $recipient = $exalt ? self::exaltAddress() : data_get($agreement->data, 'recipient_email');
        if (! filter_var((string) $recipient, FILTER_VALIDATE_EMAIL)
            || ! $agreement->signed_pdf_path || ! Storage::disk('local')->exists($agreement->signed_pdf_path)) {
            return false;
        }

        return $this->queue($agreement, $exalt ? 'exalt_copy' : 'client_copy', $recipient,
            new AccommodationSignedCopy($agreement->client_name, $agreement->signed_pdf_path, forExalt: $exalt));
    }

    private function queue(AccommodationAgreement $agreement, string $kind, string $recipient, AgreementSigningLink|AccommodationSignedCopy $mail): bool
    {
        try {
            $event = $agreement->emails()->create(['kind' => $kind]);
            $mail->agreementEmailId = $event->id;
            Mail::to($recipient)->queue($mail);

            return true;
        } catch (Throwable $error) {
            if (isset($event)) {
                $mail->failed($error);
            }
            Log::error('Agreement email could not be queued', ['agreement' => $agreement->id, 'kind' => $kind, 'error' => $error->getMessage()]);

            return false;
        }
    }
}
