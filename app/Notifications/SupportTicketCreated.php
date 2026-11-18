<?php

namespace App\Notifications;

use App\Models\SystemTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SupportTicketCreated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SystemTicket $ticket)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('['.strtoupper($this->ticket->priority).'] '.$this->ticket->ticket_ref.' — '.$this->ticket->title)
            ->line('Ticket: '.$this->ticket->title)
            ->line('Sent by: '.($this->ticket->submitter?->name ?? 'Unknown').' ('.($this->ticket->submitter?->email ?? 'Unavailable').')')
            ->line('Department: '.($this->ticket->department ?: 'Unspecified'))
            ->line('Urgency: '.ucfirst($this->ticket->priority))
            ->line('Assigned to: '.($this->ticket->assignee?->name ?? 'Unassigned'))
            ->action('View ticket', url('/admin/system-tickets?search='.$this->ticket->ticket_ref));
    }
}
