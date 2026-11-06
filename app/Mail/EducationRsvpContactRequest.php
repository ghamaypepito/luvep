<?php

namespace App\Mail;

use App\Models\EducationRsvp;
use App\Services\EducationRsvpContactUpdate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EducationRsvpContactRequest extends Mailable
{
    use Queueable, SerializesModels;

    public string $updateUrl;

    public function __construct(public EducationRsvp $rsvp)
    {
        $this->updateUrl = app(EducationRsvpContactUpdate::class)->url($rsvp);
    }

    public function build()
    {
        return $this->subject('Add your contact number for EP Education event updates')->replyTo('emma@luvep.com')
            ->view('emails.education-rsvp-contact-request')->text('emails.education-rsvp-contact-request-text');
    }

    public function logBody(string $html): string
    {
        return str_replace([e($this->updateUrl), $this->updateUrl], '#secure-contact-update-link', $html);
    }
}
