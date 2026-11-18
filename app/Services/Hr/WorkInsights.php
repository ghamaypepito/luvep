<?php

namespace App\Services\Hr;

use App\Models\DtrEntry;
use App\Models\DtrSetting;
use App\Models\HrDtrRevision;
use App\Models\HrEmployee;
use App\Models\LeadTask;
use App\Models\User;
use App\Support\TaskDestination;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class WorkInsights
{
    public function summary(User $viewer, string $period = 'week', int $page = 1, bool $selfOnly = false): array
    {
        $access = app(HrAccess::class);
        abort_unless($access->internal($viewer), 403);
        $timezone = DtrSetting::where('user_id', $viewer->id)->value('timezone') ?: config('app.timezone', 'UTC');
        $now = CarbonImmutable::instance(now($timezone));
        $from = match ($period) {
            'today' => $now->startOfDay(),
            'month' => $now->startOfMonth(),
            default => $now->startOfWeek(),
        };
        $start = $from->utc();
        $end = $now->utc();
        $global = ! $selfOnly && $viewer->isAdmin();
        $roster = User::where('is_active', true)->whereIn('role', config('hr.internal_roles'))
            ->whereNotIn('id', HrEmployee::where('status', '!=', 'active')->select('user_id'))
            ->whereNotIn('id', DtrSetting::whereNotNull('archived_at')->select('user_id'));
        if (! $global) {
            $team = HrEmployee::where('status', 'active')->where(fn ($q) => $q->where('manager_user_id', $viewer->id)->orWhere('reviewer_user_id', $viewer->id));
            $roster->where(fn ($q) => $q->where('id', $viewer->id)->when(! $selfOnly && $access->employee($viewer), fn ($q) => $q->orWhereIn('id', $team->select('user_id'))));
        }
        $members = $roster->orderBy('name')->orderBy('id')->get(['id', 'name', 'role'])->keyBy('id');
        $ids = $members->keys()->all();
        $zero = ['not_started' => 0, 'in_progress' => 0, 'in_review' => 0, 'completed' => 0, 'overdue' => 0, 'updated' => 0];
        $summary = $zero;
        $rows = $members->map(fn ($member) => ['id' => $member->id, 'name' => $member->name, 'role' => $member->role, ...$zero])->all();
        $recent = [];
        $undated = 0;
        // No board display limit is applied to aggregates. Only the recent feed is capped.
        $tasks = $this->assignedTasks($ids)->select(['id', 'title', 'created_by', 'assignee_id', 'additional_assignee_ids', 'status', 'completed', 'completed_at', 'due_at', 'updated_at', 'progress'])
            ->withCount(['comments as comments_in_period' => fn ($q) => $q->whereBetween('created_at', [$start, $end])])
            ->withMax(['comments as recent_comment_at' => fn ($q) => $q->whereBetween('created_at', [$start, $end])], 'created_at')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('completed', false)->where('status', '!=', 'completed'))
                ->orWhereNull('completed_at')->orWhereBetween('completed_at', [$start, $end])->orWhereBetween('updated_at', [$start, $end])
                ->orWhereHas('comments', fn ($q) => $q->whereBetween('created_at', [$start, $end])));
        foreach ($tasks->cursor() as $task) {
            $assignees = array_values(array_intersect($task->allAssigneeIds(), $ids));
            if (! $assignees) {
                continue;
            }
            $done = $task->completed || $task->status === 'completed';
            $status = $done ? 'completed' : (in_array($task->status, ['in_progress', 'in_review'], true) ? $task->status : 'not_started');
            $completed = $done && $task->completed_at?->betweenIncluded($start, $end);
            $overdue = ! $done && $task->due_at && $task->due_at->lt($end);
            $updatedAt = $task->updated_at?->betweenIncluded($start, $end) ? $task->updated_at->toImmutable() : null;
            $commentAt = $task->recent_comment_at ? CarbonImmutable::parse($task->recent_comment_at, 'UTC') : null;
            $activityAt = $updatedAt && $commentAt ? $updatedAt->max($commentAt) : ($updatedAt ?? $commentAt);
            if ($done && ! $task->completed_at) {
                $undated++;
            }
            $increments = [];
            if (! $done) {
                $increments[] = $status;
            }
            if ($completed) {
                $increments[] = 'completed';
            }
            if ($overdue) {
                $increments[] = 'overdue';
            }
            if ($activityAt) {
                $increments[] = 'updated';
            }
            foreach ($increments as $key) {
                $summary[$key]++;
                foreach ($assignees as $id) {
                    $rows[$id][$key]++;
                }
            }
            if ($activityAt) {
                $canRead = $viewer->isAdmin() || (int) $task->created_by === $viewer->id || in_array($viewer->id, $task->allAssigneeIds(), true);
                $canOpen = $viewer->isAdmin() || in_array($viewer->id, $task->allAssigneeIds(), true);
                $destination = $canOpen && ! $selfOnly ? TaskDestination::forUser($viewer, $task->id) : null;
                $recent[] = ['id' => $task->id, 'title' => $canRead ? $task->title : 'Assigned task #'.$task->id,
                    'status' => $status, 'progress' => (int) $task->progress, 'updated_at' => $activityAt->toISOString(),
                    'comments' => (int) $task->comments_in_period,
                    'assignees' => array_map(fn ($id) => $members[$id]->name, $assignees),
                    'url' => $destination && str_contains($destination, '?task=') ? $destination : null];
                usort($recent, fn ($a, $b) => strcmp($b['updated_at'], $a['updated_at']) ?: $b['id'] <=> $a['id']);
                $recent = array_slice($recent, 0, 8);
            }
        }
        $lastPage = max(1, (int) ceil(count($rows) / 20));
        $page = min(max(1, $page), $lastPage);

        return ['scope' => $global ? 'all_staff' : (count($rows) > 1 ? 'assigned_team' : 'self'),
            'period' => $period, 'timezone' => $timezone, 'from' => $from->toDateString(), 'to' => $now->toDateString(), 'as_of' => $now->toISOString(),
            'summary' => $summary, 'undated_completions' => $undated,
            'members' => ['data' => array_slice(array_values($rows), ($page - 1) * 20, 20), 'total' => count($rows), 'current_page' => $page, 'last_page' => $lastPage],
            'recent_updates' => $recent, 'daily_reports' => $this->reports($viewer, $ids, $from, $now, $selfOnly)];
    }

    private function assignedTasks(array $ids): Builder
    {
        return LeadTask::where(function ($q) use ($ids) {
            $q->whereIn('assignee_id', $ids);
            foreach ($ids as $id) {
                $q->orWhereJsonContains('additional_assignee_ids', (int) $id)->orWhereJsonContains('additional_assignee_ids', (string) $id);
            }
        });
    }

    private function reports(User $viewer, array $ids, CarbonImmutable $from, CarbonImmutable $now, bool $selfOnly): array
    {
        $allowed = $selfOnly ? [] : app(DtrHistory::class)->team($viewer)->whereIn('user_id', $ids)->pluck('user_id')->all();
        if (in_array($viewer->id, $ids, true) && app(HrAccess::class)->employee($viewer)) {
            $allowed[] = $viewer->id;
        }
        $allowed = array_values(array_unique($allowed));
        $stats = ['records' => 0, 'completed_tasks' => 0, 'in_progress_tasks' => 0, 'submitted' => 0, 'recent_updates' => []];
        $records = DtrEntry::whereIn('user_id', $allowed)->whereDate('work_date', '>=', $from->toDateString())->whereDate('work_date', '<=', $now->toDateString())
            ->whereIn('hr_status', ['draft', 'returned', 'submitted', 'reviewed'])->get(['id', 'tasks', 'hr_status']);
        foreach ($records as $record) {
            $stats['records']++;
            if (in_array($record->hr_status, ['submitted', 'reviewed'], true)) {
                $stats['submitted']++;
            }
            foreach ($record->tasks ?? [] as $task) {
                if (($task['status'] ?? null) === 'done') {
                    $stats['completed_tasks']++;
                }
                if (($task['status'] ?? null) === 'in_progress') {
                    $stats['in_progress_tasks']++;
                }
            }
        }
        $names = User::whereIn('id', $allowed)->pluck('name', 'id');
        $stats['recent_updates'] = HrDtrRevision::with('entry:id,user_id,work_date')
            ->whereHas('entry', fn ($q) => $q->whereIn('user_id', $allowed))
            ->whereBetween('created_at', [$from->utc(), $now->utc()])->latest('id')->limit(5)->get()
            ->map(fn ($revision) => ['id' => $revision->id, 'name' => $names[$revision->entry->user_id] ?? 'Team member',
                'work_date' => $revision->entry->work_date->toDateString(), 'action' => $revision->action,
                'changed_fields' => count($revision->changes), 'recorded_at' => $revision->created_at->toISOString()])->all();

        return $stats;
    }
}
