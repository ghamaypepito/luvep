<?php

namespace App\Mail;

use App\Models\AccommodationAgreementEmail;
use Illuminate\Support\Facades\DB;

trait TracksAccommodationAgreementEmail
{
    public ?int $agreementEmailId = null;

    public function send($mailer)
    {
        if ($this->agreementEmailId === null) {
            return parent::send($mailer);
        }

        return DB::transaction(function () use ($mailer) {
            $event = AccommodationAgreementEmail::whereKey($this->agreementEmailId)->lockForUpdate()->first();
            if (! $event || $event->status === 'sent') {
                return null;
            }

            $sent = parent::send($mailer);
            $event->update($sent
                ? ['status' => 'sent', 'sent_at' => now()]
                : ['status' => 'failed']);

            return $sent;
        });
    }

    public function failed($error): void
    {
        if ($this->agreementEmailId !== null) {
            AccommodationAgreementEmail::whereKey($this->agreementEmailId)
                ->where('status', '!=', 'sent')->update(['status' => 'failed']);
        }
    }
}
