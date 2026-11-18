<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class EngagementSignatureUploaded extends Notification
{
    public function __construct(private array $data) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->data;
    }
}
