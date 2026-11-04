<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadTask extends Model
{
    use LogsActivity;

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const TYPES = ['call', 'email', 'meeting', 'document', 'follow_up', 'internal', 'other'];

    public const DEPARTMENTS = ['sales', 'education', 'immigration', 'accommodation', 'finance', 'admin'];

    public const STATUSES = ['not_started', 'in_progress', 'in_review', 'completed'];

    protected $fillable = [
        'lead_id', 'additional_lead_ids', 'created_by', 'assignee_id', 'additional_assignee_ids',
        'title', 'description', 'note', 'due_at', 'snoozed_until', 'priority', 'progress',
        'completed', 'completed_at', 'completed_by',
        'type', 'category', 'department', 'tags',
        'recurrence_config', 'cross_dept_reason',
        'status', 'completion_notes',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'completed_at' => 'datetime',
        'completed' => 'boolean',
        'tags' => 'array',
        'recurrence_config' => 'array',
        'additional_assignee_ids' => 'array',
        'additional_lead_ids' => 'array',
    ];

    /**
     * Return every assigned user id — primary first, then co-assignees,
     * de-duped and re-indexed. Use in views that want to render every
     * avatar instead of just the primary.
     */
    public function allAssigneeIds(): array
    {
        $ids = array_merge(
            $this->assignee_id ? [(int) $this->assignee_id] : [],
            array_map('intval', $this->additional_assignee_ids ?? [])
        );

        return array_values(array_unique($ids));
    }

    /**
     * Primary lead id (if any) plus every co-linked lead id, de-duped.
     */
    public function allLeadIds(): array
    {
        $ids = array_merge(
            $this->lead_id ? [(int) $this->lead_id] : [],
            array_map('intval', $this->additional_lead_ids ?? [])
        );

        return array_values(array_unique($ids));
    }

    /**
     * Keep `status` and the legacy `completed` flag in lockstep regardless
     * of which write path mutates the row. Lets the kanban PATCH endpoint
     * use `status` while LeadTaskController::update keeps using `completed`
     * — both paths produce coherent rows.
     */
    protected static function booted(): void
    {
        static::saving(function (self $task) {
            $statusDirty = $task->isDirty('status');
            $completedDirty = $task->isDirty('completed');

            if ($statusDirty && ! $completedDirty) {
                $task->completed = $task->status === 'completed';
            } elseif ($completedDirty && ! $statusDirty) {
                $task->status = $task->completed ? 'completed' : 'not_started';
            }
            // If both were set explicitly, trust whatever the caller wrote.

            // Timestamp/audit fields follow `completed` — historical
            // behavior preserved so existing reports keep working.
            if ($task->isDirty('completed')) {
                $task->completed_at = $task->completed ? ($task->completed_at ?? now()) : null;
                if (! $task->completed) {
                    $task->completed_by = null;
                }
            }
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function attachments()
    {
        return $this->hasMany(LeadTaskAttachment::class, 'lead_task_id');
    }

    public function comments()
    {
        return $this->hasMany(LeadTaskComment::class, 'lead_task_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Primary and secondary assignments share the same personal task queue. */
    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where(fn ($assigned) => $assigned
            ->where('assignee_id', $userId)
            ->orWhereJsonContains('additional_assignee_ids', $userId)
            ->orWhereJsonContains('additional_assignee_ids', (string) $userId));
    }

    /** Open + overdue. Powers the sales "Due today" widget. */
    public function scopeDueToday(Builder $q): Builder
    {
        return $q->where('completed', false)
            ->whereNotNull('due_at')
            ->whereDate('due_at', '<=', now()->toDateString());
    }

    public function activityNoun(): string
    {
        return 'lead_task';
    }
}
