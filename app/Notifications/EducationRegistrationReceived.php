<?php

namespace App\Notifications;

use App\Models\EducationEventRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EducationRegistrationReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public EducationEventRegistration $registration) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $r = $this->registration;

        return [
            'title' => 'New Education registration',
            'body' => $r->contact_details['first_name'].' '.$r->contact_details['last_name'].' joined '.$r->event_name.' ('.$r->school.').',
            'lead_id' => $r->lead_id, 'registration_id' => $r->id,
            'link' => '/portal/education/leads/'.$r->lead_id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->registration;

        return (new MailMessage)->subject('New Education registration — '.$r->school)
            ->view('emails.education-registration-staff', ['registration' => $r]);
    }
}
