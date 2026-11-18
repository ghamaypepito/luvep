<?php

namespace App\Notifications;

use App\Models\CreativeRequest;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CreativeRequestAssigned extends Notification
{
    public function __construct(public CreativeRequest $request, public string $actor) {}

    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'New creative request assigned to you',
            'body' => "{$this->actor}: {$this->request->task->title} · Due {$this->request->brief['deadline']}",
            'task_id' => $this->request->lead_task_id, 'creative_request_id' => $this->request->id,
            'link' => $this->request->url()];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Creative request {$this->request->request_ref}: {$this->request->task->title}")
            ->greeting('Hi Sharry,')->line("{$this->actor} submitted a creative request assigned to you.")
            ->line('Project: '.$this->request->task->title)
            ->line('Deadline: '.$this->request->brief['deadline'])
            ->action('Open creative brief', $this->request->url())
            ->line('Sign in to Luvep to view the caption, reference designs and full brief.');
    }
}
