<?php

namespace App\Models;

use App\Support\TicketAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreativeRequest extends Model
{
    use SoftDeletes;

    public const DESIGN_TYPES = ['Digital media', 'Print media', 'Video / reel', 'Other'];

    public const LEGACY_DESIGN_TYPES = ['Social media graphic', 'Poster / flyer'];

    protected $fillable = ['request_ref', 'lead_task_id', 'brief', 'attachments'];

    protected $hidden = ['attachments'];

    protected $casts = ['brief' => 'array', 'attachments' => 'array'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(LeadTask::class, 'lead_task_id')->withTrashed();
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->when(! $user->isAdmin(), fn ($q) => $q->whereHas('task', fn ($t) => $t->where('created_by', $user->id)->orWhere(fn ($t) => $t->assignedTo($user->id))));
    }

    public function canManage(User $user): bool
    {
        return $user->isAdmin() || in_array($user->id, $this->task->allAssigneeIds(), true);
    }

    public function url(): string
    {
        return rtrim(config('hr.main_url'), '/').'/creative-requests/'.$this->id;
    }

    public function row(bool $detail = false): array
    {
        $task = $this->task;
        $viewer = auth()->user();
        $deleted = $this->trashed();

        return [
            'id' => $this->id, 'request_ref' => $this->request_ref, 'title' => $task->title,
            'status' => $task->status, 'deadline' => $task->due_at?->copy()->timezone($this->brief['timezone'] ?? 'Asia/Manila')->toDateString() ?? ($this->brief['deadline'] ?? null),
            'types' => $this->brief['types'], 'assignee' => $task->assignee?->name,
            'submitter' => $task->creator?->name, 'attachment_count' => count($this->attachments ?? []),
            'task_id' => $task->id, 'url' => $this->url(),
            'is_deleted' => $deleted,
            'can_manage' => ! $deleted && ! $task->trashed() && $viewer && $this->canManage($viewer),
            'can_delete' => ! $deleted && (bool) $viewer?->isAdmin(),
            'can_restore' => $deleted && (bool) $viewer?->isAdmin(),
            ...($detail ? ['brief' => $this->brief,
                'attachments' => TicketAttachments::serialize($this->attachments ?? [], "/creative-requests/{$this->id}/attachments")] : []),
        ];
    }

    public static function assignedFor(User $user): array
    {
        return static::with(['task.assignee:id,name', 'task.creator:id,name'])
            ->whereHas('task', fn ($q) => $q->assignedTo($user->id)->where('completed', false))
            ->latest()->get()->map(fn ($request) => $request->row())->all();
    }
}
