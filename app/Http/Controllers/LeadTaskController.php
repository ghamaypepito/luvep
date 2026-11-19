<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadTask;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Follow-up tasks per lead. Any staff role can create + complete tasks;
 * only the creator or admin can delete.
 */
class LeadTaskController extends Controller
{
    public function store(Request $request, $leadId)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'due_at' => 'nullable|date',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'assignee_id' => 'nullable|exists:users,id',
        ]);

        try {
            $lead = Lead::findOrFail($leadId);

            $assigneeId = $validated['assignee_id'] ?? Auth::id();
            $task = LeadTask::create([
                'lead_id' => $lead->id,
                'created_by' => Auth::id(),
                'assignee_id' => $assigneeId,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'due_at' => $validated['due_at'] ?? null,
                'priority' => $validated['priority'] ?? 'normal',
            ]);

            // Notify the assignee when it's someone other than the creator —
            // in-app bell + an email with the full task detail and attachments.
            if ($assigneeId && (int) $assigneeId !== (int) Auth::id()) {
                try {
                    if ($assignee = \App\Models\User::find($assigneeId)) {
                        $actorName = Auth::user()?->name;
                        $assignee->notify(new \App\Notifications\TaskAssigned($task, $actorName));
                        if ($assignee->email) {
                            $task->loadMissing('attachments', 'lead');
                            \Illuminate\Support\Facades\Mail::to($assignee->email)
                                ->queue(new \App\Mail\TaskAssignedMail($task, $assignee, $actorName));
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('Lead task notify failed', ['task_id' => $task->id, 'error' => $e->getMessage()]);
                }
            }

            return back()->with('success', "Task “{$task->title}” added.");
        } catch (\Throwable $e) {
            Log::error('Lead task create failed', ['lead_id' => $leadId, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not create task.']);
        }
    }

    public function update(Request $request, $leadId, $taskId)
    {
        abort_if(\App\Models\CreativeRequest::withTrashed()->where('lead_task_id', $taskId)->exists(), 403, 'Update creative requests from the task board or Digital queue.');

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
            'due_at' => 'sometimes|nullable|date',
            'priority' => 'sometimes|in:low,normal,high,urgent',
            'assignee_id' => 'sometimes|nullable|exists:users,id',
            'completed' => 'sometimes|boolean',
        ]);

        try {
            $task = LeadTask::where('lead_id', $leadId)->findOrFail($taskId);

            if (isset($validated['completed'])) {
                $task->completed = (bool) $validated['completed'];
                $task->completed_at = $validated['completed'] ? now() : null;
                $task->completed_by = $validated['completed'] ? Auth::id() : null;
            }

            $fill = collect($validated)->except(['completed'])->all();
            empty($fill) ?: $task->fill($fill);

            $task->save();

            return back()->with('success', 'Task updated.');
        } catch (\Throwable $e) {
            Log::error('Lead task update failed', ['task_id' => $taskId, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not update task.']);
        }
    }

    public function destroy(Request $request, $leadId, $taskId)
    {
        abort_if(\App\Models\CreativeRequest::withTrashed()->where('lead_task_id', $taskId)->exists(), 403, 'Delete creative requests from the Digital queue so they can be recovered.');

        try {
            $task = LeadTask::where('lead_id', $leadId)->findOrFail($taskId);
            $user = Auth::user();

            $canDelete = $user?->isAdmin() || $task->created_by === $user?->id;
            $canDelete ? $task->forceDelete() : abort(403, 'You can only delete tasks you created.');

            return back()->with('success', 'Task removed.');
        } catch (\Throwable $e) {
            Log::error('Lead task delete failed', ['task_id' => $taskId, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not delete task.']);
        }
    }

    /** Sales-portal widget feed — current user's due-today + overdue tasks. */
    public function dueToday()
    {
        try {
            $tasks = LeadTask::with('lead:id,lead_id,first_name,last_name,email')
                ->where('assignee_id', Auth::id())
                ->dueToday()
                ->orderBy('due_at')
                ->limit(50)
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'priority' => $t->priority,
                    'due_at' => $t->due_at,
                    'overdue' => $t->due_at && $t->due_at->isPast(),
                    'lead' => $t->lead ? [
                        'id' => $t->lead->id,
                        'lead_id' => $t->lead->lead_id,
                        'name' => trim("{$t->lead->first_name} {$t->lead->last_name}"),
                    ] : null,
                ]);

            return response()->json($tasks);
        } catch (\Throwable $e) {
            Log::error('Due-today task feed failed', ['error' => $e->getMessage()]);

            return response()->json([], 500);
        }
    }
}
