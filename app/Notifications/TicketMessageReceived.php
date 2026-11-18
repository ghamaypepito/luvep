<?php

namespace App\Notifications;

use App\Models\SystemTicket;
use Illuminate\Notifications\Notification;

class TicketMessageReceived extends Notification
{
    public function __construct(public readonly SystemTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'New ticket reply', 'body' => "A new reply is available on {$this->ticket->ticket_ref}.", 'ticket_id' => $this->ticket->id, 'ticket_ref' => $this->ticket->ticket_ref, 'link' => $notifiable->isAdmin() ? '/admin/system-tickets' : '/portal/tickets'];
    }
}
