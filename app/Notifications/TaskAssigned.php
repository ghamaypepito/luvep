<?php

namespace App\Notifications;

use App\Models\LeadTask;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to a staff member when a task is assigned to them (on creation or
 * when added as an assignee on update). Database channel only.
 */
class TaskAssigned extends Notification
{
    use Queueable;

    public function __construct(
        public readonly LeadTask $task,
        public readonly ?string $assignedByName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $actor = $this->assignedByName ?? 'A staff member';
        $due = $this->task->due_at ? ' (due ' . $this->task->due_at->format('d M') . ')' : '';

        return [
            'title'      => 'New task assigned to you',
            'body'       => "{$actor} assigned you: \"{$this->task->title}\"{$due}.",
            'task_id'    => $this->task->id,
            'lead_id'    => $this->task->lead_id,
            'link'       => ($notifiable->role ?? null) === 'finance'
                ? '/portal/finance/tasks?task='.$this->task->id
                : ($this->task->lead_id
                    ? "/admin/leads/{$this->task->lead_id}"
                    : '/admin/tasks'),
        ];
    }
}
