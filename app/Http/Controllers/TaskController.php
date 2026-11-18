<?php

namespace App\Http\Controllers;

use App\Mail\TaskAssignedMail;
use App\Models\Lead;
use App\Models\LeadTask;
use App\Models\LeadTaskAttachment;
use App\Models\LeadTaskComment;
use App\Models\User;
use App\Notifications\TaskAssigned;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Cross-portal Task API used by the New Task modal (see resources/js/
 * components/task-board/NewTaskModal.jsx).
 *
 * The existing per-lead endpoint POST /admin/leads/{id}/tasks still works
 * and is unchanged — it remains the path used by the lead detail page's
 * "Add Task" panel. This controller adds:
 *
 *   POST /api/tasks                 — create a task (lead-linked OR dept)
 *   GET  /api/tasks/related-records — autocomplete for "Related to" field
 *
 * Both run inside the `web` middleware group so they're session-auth and
 * CSRF-protected the same way every other Inertia POST is.
 */
class TaskController extends Controller
{
    public function store(Request $request)
    {
        $taskType = $request->input('task_type'); // linked | dept
        $user = $request->user();

        $rules = [
            'task_type' => 'required|in:linked,dept',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'note' => 'nullable|string|max:500',
            'type' => ['nullable', Rule::in(LeadTask::TYPES)],
            'priority' => ['nullable', Rule::in(LeadTask::PRIORITIES)],
            'progress' => 'nullable|integer|min:0|max:100',
            'due_at' => 'required|date|after_or_equal:today',
            // Multi-assignee: prefer `assignee_ids[]`, fall back to the
            // legacy single `assignee_id` if the caller only supplies one.
            'assignee_id' => 'nullable|exists:users,id',
            'assignee_ids' => 'nullable|array|max:20',
            'assignee_ids.*' => 'integer|exists:users,id',
            'department' => ['nullable', Rule::in(LeadTask::DEPARTMENTS)],
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'recurrence_config' => 'nullable|array',
            'cross_dept_reason' => 'nullable|string|max:500',
            // Any file type up to 20 MB each (images, video, PDF, docs,
            // audio). The kanban card renders the first image as a
            // thumbnail and surfaces the total count on the paperclip.
            'attachments' => 'nullable|array|max:8',
            'attachments.*' => \App\Support\UploadValidation::taskAttachment(),
        ];

        if ($taskType === 'linked') {
            // `lead_ids[]` is the multi-link path; `lead_id` is kept for
            // backwards compatibility when the caller only links one lead.
            // The rules below require at least one (validated after).
            $rules['lead_id'] = 'nullable|exists:leads,id';
            $rules['lead_ids'] = 'nullable|array|max:50';
            $rules['lead_ids.*'] = 'integer|exists:leads,id';
            $rules['category'] = 'nullable|string|max:100';
        } else {
            $rules['lead_id'] = 'nullable';
            $rules['lead_ids'] = 'nullable|array|max:50';
            $rules['category'] = 'required|string|max:100';
        }

        $validated = $request->validate($rules);

        // Department — admins/super-admins may explicitly pick one; everyone
        // else is locked to their own role. Stored on the task so the cross-
        // dept badge + filters work even after the assignee changes.
        $department = $validated['department']
            ?? ($user->isAdmin() ? null : $user->role);

        // Resolve final assignee list — prefer the new multi-assignee
        // `assignee_ids` array; fall back to the legacy single field; default
        // to the current user if nothing was supplied. First id becomes the
        // primary (kept on assignee_id for ownership / scope compatibility);
        // the rest land in additional_assignee_ids.
        $assigneeIds = array_values(array_unique(array_filter(array_map('intval',
            $validated['assignee_ids'] ?? array_filter([$validated['assignee_id'] ?? null])
        ))));
        if (empty($assigneeIds)) {
            $assigneeIds = [$user->id];
        }
        $primaryAssigneeId = $assigneeIds[0];
        $additionalIds = array_slice($assigneeIds, 1);

        // Cross-department assignment is allowed without justification —
        // we still persist any reason the caller sends, but never require it.
        $assignees = User::whereIn('id', $assigneeIds)->get();
        $isCrossDept = $department && $assignees->contains(
            fn (User $u) => ! $u->isAdmin() && $u->role && $u->role !== $department
        );

        // Resolve final lead list — prefer `lead_ids` array; fall back to
        // the legacy single `lead_id`. First id becomes the primary
        // (kept on lead_id for relation / activity-log compatibility);
        // the rest land in additional_lead_ids.
        $leadIds = [];
        if ($taskType === 'linked') {
            $leadIds = array_values(array_unique(array_filter(array_map('intval',
                $validated['lead_ids'] ?? array_filter([$validated['lead_id'] ?? null])
            ))));
            if (empty($leadIds)) {
                return back()->withErrors(['lead_ids' => 'Pick at least one lead.']);
            }
        }
        $primaryLeadId = $leadIds[0] ?? null;
        $additionalLeadIds = count($leadIds) > 1 ? array_slice($leadIds, 1) : null;

        try {
            $task = LeadTask::create([
                'lead_id' => $primaryLeadId,
                'additional_lead_ids' => $additionalLeadIds,
                'created_by' => $user->id,
                'assignee_id' => $primaryAssigneeId,
                'additional_assignee_ids' => $additionalIds ?: null,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'note' => $validated['note'] ?? null,
                'type' => $validated['type'] ?? null,
                'category' => $validated['category'] ?? null,
                'department' => $department,
                'priority' => $validated['priority'] ?? 'normal',
                'progress' => $validated['progress'] ?? 0,
                'due_at' => $validated['due_at'],
                'tags' => $validated['tags'] ?? null,
                'recurrence_config' => $validated['recurrence_config'] ?? null,
                'cross_dept_reason' => $isCrossDept ? ($validated['cross_dept_reason'] ?? null) : null,
            ]);

            $this->storeAttachments($request, $task, $user);

            // Notify everyone the task was assigned to (except the creator).
            $this->notifyAssignees($task, $assigneeIds, $user->id);

            return back()->with('success', "Task “{$task->title}” created.");
        } catch (\Throwable $e) {
            Log::error('Task create failed', ['error' => $e->getMessage(), 'user_id' => $user->id]);

            return back()->withErrors(['error' => 'Could not create task.']);
        }
    }

    /**
     * Patch a single task. Used by the kanban view to flip status as cards
     * are dragged between columns, and to capture optional completion notes
     * when a card is dropped into "Completed".
     *
     * Permission model (mirrored in the kanban UI's lock icon):
     *   - Admins may update any task.
     *   - Other staff may update tasks they own (assignee_id) or that belong
     *     to their department.
     *   - Everything else → 403.
     */
    public function update(Request $request, int $id)
    {
        $user = $request->user();
        $task = LeadTask::findOrFail($id);

        $canEdit = $user->isAdmin()
            || $task->created_by === $user->id
            || $task->assignee_id === $user->id
            || in_array($user->id, $task->additional_assignee_ids ?? [], true)
            || ($task->department && $task->department === $user->role);

        abort_unless($canEdit, 403, 'You do not have permission to update this task.');

        // Snapshot current assignees so we can notify anyone newly added.
        $assigneesBefore = $task->allAssigneeIds();

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(LeadTask::STATUSES)],
            'completion_notes' => 'sometimes|nullable|string|max:2000',
            'priority' => ['sometimes', Rule::in(LeadTask::PRIORITIES)],
            'progress' => 'sometimes|integer|min:0|max:100',
            'title' => 'sometimes|string|max:200',
            'description' => 'sometimes|nullable|string|max:2000',
            'note' => 'sometimes|nullable|string|max:500',
            'due_at' => 'sometimes|nullable|date',
            'assignee_id' => 'sometimes|nullable|exists:users,id',
            'assignee_ids' => 'sometimes|nullable|array|max:20',
            'assignee_ids.*' => 'integer|exists:users,id',
            'lead_id' => 'sometimes|nullable|exists:leads,id',
            'lead_ids' => 'sometimes|nullable|array|max:50',
            'lead_ids.*' => 'integer|exists:leads,id',
            'department' => ['sometimes', Rule::in(LeadTask::DEPARTMENTS)],
            'cross_dept_reason' => 'sometimes|nullable|string|max:500',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'string|max:50',
            'type' => ['sometimes', 'nullable', Rule::in(LeadTask::TYPES)],
            'category' => 'sometimes|nullable|string|max:100',
        ]);

        try {
            if (array_key_exists('status', $validated)) {
                $task->status = $validated['status'];
                if ($validated['status'] === 'completed') {
                    $task->completed_by = $user->id;
                    $task->completed_at = $task->completed_at ?? now();
                }
            }

            // Multi-assignee replace: an explicit `assignee_ids` overrides
            // both columns. Otherwise fall back to setting the single
            // `assignee_id` if it was sent.
            if (array_key_exists('assignee_ids', $validated)) {
                $ids = array_values(array_unique(array_filter(array_map('intval', $validated['assignee_ids'] ?? []))));
                $task->assignee_id = $ids[0] ?? null;
                $task->additional_assignee_ids = count($ids) > 1 ? array_slice($ids, 1) : null;
            } elseif (array_key_exists('assignee_id', $validated)) {
                $task->assignee_id = $validated['assignee_id'];
            }

            // Multi-lead replace: same shape as the assignee path.
            if (array_key_exists('lead_ids', $validated)) {
                $ids = array_values(array_unique(array_filter(array_map('intval', $validated['lead_ids'] ?? []))));
                $task->lead_id = $ids[0] ?? null;
                $task->additional_lead_ids = count($ids) > 1 ? array_slice($ids, 1) : null;
            } elseif (array_key_exists('lead_id', $validated)) {
                $task->lead_id = $validated['lead_id'];
            }

            foreach (['completion_notes', 'priority', 'progress', 'title', 'description', 'note', 'due_at', 'department', 'cross_dept_reason', 'tags', 'type', 'category'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $task->{$field} = $validated[$field];
                }
            }

            $task->save();

            // Notify anyone added as an assignee by this update (not re-notify
            // existing ones, and never the editor themselves).
            $added = array_diff($task->allAssigneeIds(), $assigneesBefore);
            $this->notifyAssignees($task, $added, $user->id);

            return back()->with('success', 'Task updated.');
        } catch (\Throwable $e) {
            Log::error('Task update failed', ['task_id' => $id, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not update task.']);
        }
    }

    /**
     * Send a TaskAssigned notification to the given assignee ids, excluding
     * the actor (no point pinging yourself about your own assignment).
     */
    private function notifyAssignees(LeadTask $task, array $assigneeIds, int $actorId): void
    {
        $ids = array_values(array_diff(array_map('intval', $assigneeIds), [$actorId]));
        if (empty($ids)) {
            return;
        }

        try {
            $recipients = User::whereIn('id', $ids)->get();
            if ($recipients->isEmpty()) {
                return;
            }

            // In-app bell notification (instant).
            Notification::send($recipients, new TaskAssigned($task, Auth::user()?->name));

            // Email each assignee the full task detail + any attachments. Queued
            // (the Mailable is ShouldQueue), so it never slows task creation.
            $actorName = Auth::user()?->name;
            $task->loadMissing('attachments', 'lead');
            foreach ($recipients as $recipient) {
                if (! $recipient->email) {
                    continue;
                }
                Mail::to($recipient->email)->queue(new TaskAssignedMail($task, $recipient, $actorName));
            }
        } catch (\Throwable $e) {
            Log::error('Task assignment notify failed', ['task_id' => $task->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * List comments on a task, oldest first, for its participants.
     */
    public function comments(Request $request, int $id)
    {
        $task = LeadTask::findOrFail($id);
        $this->authorizeComments($request->user(), $task);

        try {

            $rows = $task->comments()
                ->with('author:id,name,avatar_path')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'body' => $c->body,
                    'created_at' => $c->created_at,
                    'author' => $c->author ? ['id' => $c->author->id, 'name' => $c->author->name, 'avatar_url' => $c->author->avatar_url] : null,
                ]);

            return response()->json(['comments' => $rows]);
        } catch (\Throwable $e) {
            Log::error('Task comments list failed', ['task_id' => $id, 'error' => $e->getMessage()]);

            return response()->json(['comments' => []], 500);
        }
    }

    /**
     * Append a comment to a task. Author = current user; body required.
     */
    public function storeComment(Request $request, int $id)
    {
        $task = LeadTask::findOrFail($id);
        $this->authorizeComments($request->user(), $task);

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        try {
            $comment = LeadTaskComment::create([
                'lead_task_id' => $task->id,
                'user_id' => $request->user()->id,
                'body' => $validated['body'],
            ]);
            $comment->load('author:id,name,avatar_path');

            return response()->json([
                'comment' => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'created_at' => $comment->created_at,
                    'author' => $comment->author ? ['id' => $comment->author->id, 'name' => $comment->author->name, 'avatar_url' => $comment->author->avatar_url] : null,
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Task comment create failed', ['task_id' => $id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Could not add comment.'], 500);
        }
    }

    private function authorizeComments(User $user, LeadTask $task): void
    {
        // Portal grants do not turn an external account into internal staff.
        abort_unless($user->is_active && ($user->isAdmin() || in_array($user->role, [
            'sales', 'education', 'english', 'immigration', 'immigration_manager',
            'immigration_adviser', 'accommodation', 'finance',
        ], true)), 403);

        abort_unless(
            $user->isAdmin()
                || (int) $task->created_by === (int) $user->id
                || in_array((int) $user->id, $task->allAssigneeIds(), true),
            403,
            'You do not have permission to access comments on this task.'
        );
    }

    /**
     * Attach more images to an existing task. Mirrors the upload validation
     * used at task-create time so the kanban card render stays consistent.
     * The same permission rules as update() apply.
     */
    public function attach(Request $request, int $id)
    {
        $user = $request->user();
        $task = LeadTask::findOrFail($id);

        $canEdit = $user->isAdmin()
            || $task->assignee_id === $user->id
            || $task->created_by === $user->id
            || ($task->department && $task->department === $user->role);
        abort_unless($canEdit, 403, 'You do not have permission to update this task.');

        $request->validate([
            'attachments' => 'required|array|max:8',
            'attachments.*' => 'file|max:20480',
        ]);

        try {
            $this->storeAttachments($request, $task, $user);

            return back()->with('success', 'Attachment(s) added.');
        } catch (\Throwable $e) {
            Log::error('Task attachment failed', ['task_id' => $id, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not upload attachment.']);
        }
    }

    /**
     * Shared upload routine — used by store() and attach(). Files land in
     * storage/app/public/task-attachments/{taskId}/ so they're served via
     * the storage:link symlink and grouped per-task on disk.
     */
    private function storeAttachments(Request $request, LeadTask $task, User $user): void
    {
        $files = $request->file('attachments') ?? [];
        if (empty($files)) {
            return;
        }

        foreach ($files as $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }

            $path = $file->store("task-attachments/{$task->id}", 'public');

            LeadTaskAttachment::create([
                'lead_task_id' => $task->id,
                'file_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize() ?: 0,
                'uploaded_by' => $user->id,
            ]);
        }
    }

    /**
     * Autocomplete feed for the "Related to" field. Searches Leads filtered
     * by the requested record types — Students / Cases / Accommodation
     * Clients are all `Lead` rows in this codebase, distinguished by the
     * `is_student` / `is_immigration_case` / `is_accommodation_client` flags.
     */
    public function relatedRecords(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $types = array_filter(explode(',', (string) $request->input('types', 'lead,student,case,client')));
        $allTypes = ['lead', 'student', 'case', 'client'];

        // Optional explicit-id fetch — used by the edit modal to rehydrate
        // already-linked leads without having to search for them.
        $idFilter = array_values(array_filter(array_map('intval', array_filter(
            explode(',', (string) $request->input('ids', ''))
        ))));

        try {
            $query = Lead::query()->select(
                'id', 'lead_id', 'first_name', 'last_name', 'email',
                'is_student', 'is_immigration_case', 'is_accommodation_client'
            );

            if (! empty($idFilter)) {
                $query->whereIn('id', $idFilter);
            }

            // Only narrow by type when the caller has opted into a subset.
            // The default (all 4 types) returns every lead so the dropdown
            // has something useful to show the moment it opens — the type
            // flag columns can be NULL on legacy rows so the prior
            // `where(false)` predicate filtered them all out by accident.
            $subsetRequested = count($types) > 0 && count(array_diff($allTypes, $types)) > 0;
            if ($subsetRequested) {
                $query->where(function ($q2) use ($types) {
                    if (in_array('student', $types, true)) {
                        $q2->orWhere('is_student', true);
                    }
                    if (in_array('case', $types, true)) {
                        $q2->orWhere('is_immigration_case', true);
                    }
                    if (in_array('client', $types, true)) {
                        $q2->orWhere('is_accommodation_client', true);
                    }
                    if (in_array('lead', $types, true)) {
                        $q2->orWhere(function ($q3) {
                            $q3->where(fn ($w) => $w->where('is_student', false)->orWhereNull('is_student'))
                                ->where(fn ($w) => $w->where('is_immigration_case', false)->orWhereNull('is_immigration_case'))
                                ->where(fn ($w) => $w->where('is_accommodation_client', false)->orWhereNull('is_accommodation_client'));
                        });
                    }
                });
            }

            // When a query string is supplied, narrow by name / email / lead_id.
            // Otherwise return the most recent records.
            if (mb_strlen($q) >= 1) {
                $query->where(function ($q2) use ($q) {
                    $q2->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('lead_id', 'like', "%{$q}%");
                });
                $query->orderBy('first_name');
            } else {
                $query->orderByDesc('id');
            }

            $records = $query->limit(100)->get()->map(fn ($l) => [
                'id' => $l->id,
                'lead_id' => $l->lead_id,
                'name' => trim("{$l->first_name} {$l->last_name}") ?: ($l->email ?: "Lead #{$l->lead_id}"),
                'email' => $l->email,
                'record_type' => $l->is_student ? 'student'
                    : ($l->is_immigration_case ? 'case'
                    : ($l->is_accommodation_client ? 'client' : 'lead')),
            ]);

            return response()->json(['records' => $records]);
        } catch (\Throwable $e) {
            Log::error('Related records autocomplete failed', ['error' => $e->getMessage()]);

            return response()->json(['records' => []], 500);
        }
    }
}
