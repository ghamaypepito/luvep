<?php

namespace App\Mail;

use App\Models\EducationRsvp;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EducationRsvpMessage extends Mailable
{
    use Queueable,SerializesModels;

    public function __construct(public EducationRsvp $rsvp, public string $notice) {}

    public function build()
    {
        if ($this->notice === 'thank_you') {
            $message = $this->subject('Thank you for attending your eP Education orientation')
                ->replyTo('emma@luvep.com', 'Emma')
                ->view('emails.education-rsvp-thank-you');

            if ($from = config('services.contact.event_from_email')) {
                $message->from($from, 'eP Education');
            }

            return $message;
        }

        $subject = match ($this->notice) {
            'schedule' => 'Your EP Education schedule is confirmed','cancelled' => 'EP Education visit cancellation','reminder_24h','reminder_2h' => 'Reminder: your EP Education visit',default => 'Your EP Education RSVP is received'
        };

        return $this->subject($subject)->replyTo(config('services.contact.reply_to') ?: config('services.contact.email') ?: 'hello@epathways.ph')->view('emails.education-rsvp', ['rsvp' => $this->rsvp, 'notice' => $this->notice]);
    }
}
