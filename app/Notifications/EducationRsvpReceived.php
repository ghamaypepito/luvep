<?php

namespace App\Notifications;

use App\Models\EducationRsvp;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EducationRsvpReceived extends Notification
{
    public function __construct(public EducationRsvp $rsvp) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $r = $this->rsvp;

        return ['title' => 'New Education RSVP', 'body' => $r->full_name.' · '.$r->visit->school, 'rsvp_id' => $r->id, 'link' => '/portal/education/rsvps?rsvp='.$r->id];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->toArray($notifiable)['title'])->greeting('New RSVP received')->line($this->rsvp->full_name.' · '.$this->rsvp->visit->school)->line($this->rsvp->visit->summary())->line('This is an RSVP, not a programme application.')->action('View RSVP', url('/portal/education/rsvps?rsvp='.$this->rsvp->id));
    }
}
