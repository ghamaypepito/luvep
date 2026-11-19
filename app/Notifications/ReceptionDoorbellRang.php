<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ReceptionDoorbellRang extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Someone is at reception',
            'body' => 'A visitor rang the Reception QR doorbell. Please check the entrance.',
            'link' => '/portal/immigration/reception',
        ];
    }
}
