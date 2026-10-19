<?php

namespace App\Notifications;

use App\Models\HrEvent;
use App\Services\Hr\HrNotificationRecipients;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HrActionRequired extends Notification
{
    public function __construct(public HrEvent $event) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'Luvep HR update', 'body' => 'An HR record has been submitted or updated. Sign in to view the information available to you.', 'link' => app(HrNotificationRecipients::class)->link($this->event, $notifiable)];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        return (new MailMessage)->subject($data['title'])->greeting('Hello '.$notifiable->name.',')->line($data['body'])->action('Open Luvep HR', $data['link'])->line('Access is limited to your assigned responsibilities.')->salutation('Luv, eP. · People & culture');
    }
}
