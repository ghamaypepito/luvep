<?php

namespace App\Services\Hr;

use App\Models\DtrEntry;
use App\Models\DtrLeave;
use App\Models\HrDtrAcknowledgement;
use App\Models\HrEmployee;
use App\Models\HrShift;
use App\Models\User;
use Carbon\CarbonImmutable;

class TeamWorkspace
{
    public function forActor(User $actor, bool $hasEmployee): ?array
    {
        $access = app(HrAccess::class);
        $permissions = $access->menuPermissions($actor);
        if (! $access->canViewStaffStatus($actor, $permissions, $hasEmployee)) {
            return null;
        }

        $global = $permissions['dtr.review'] || $permissions['reports'];
        $employees = HrEmployee::with(['user:id,name', 'schedule'])
            ->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->where('is_active', true)->whereIn('role', config('hr.internal_roles')))
            ->whereDoesntHave('schedule', fn ($q) => $q->whereNotNull('archived_at'))
            ->when(! $global, fn ($q) => $q->where('user_id', '!=', $actor->id)->where(fn ($q) => $q->where('manager_user_id', $actor->id)->orWhere('reviewer_user_id', $actor->id)))
            ->get();
        $shifts = HrShift::with(['breaks' => fn ($q) => $q->latest('started_at')])
            ->whereIn('hr_employee_id', $employees->pluck('id'))->whereNull('ended_at')->latest('started_at')->get()->unique('hr_employee_id')->keyBy('hr_employee_id');
        $now = CarbonImmutable::instance(now());
        $clock = app(ClockService::class);
        $members = $employees->sortBy(fn ($employee) => mb_strtolower($employee->user->name))->map(function ($employee) use ($shifts, $now, $clock) {
            $shift = $shifts->get($employee->id);
            $break = $shift?->breaks->first(fn ($pause) => $pause->ended_at === null);

            return ['id' => $employee->id, 'name' => $employee->user->name, 'department' => $employee->department,
                'status' => $break ? 'on_break' : ($shift ? 'working' : 'off_shift'),
                'started_at' => $shift?->started_at?->toISOString(), 'break_started_at' => $break?->started_at?->toISOString(),
                'timezone' => $shift?->timezone ?? $employee->schedule?->timezone ?? config('app.timezone', 'UTC'),
                'max_working_seconds' => $shift?->schedule_snapshot['max_working_seconds'] ?? null,
                ...($shift ? $clock->totals($shift, $now) : ['working_seconds' => 0, 'break_seconds' => 0])];
        })->values();

        $canReviewLeave = $permissions['leave.review'] || $permissions['leave.fallback'] || $permissions['leave.emergency'];
        $canReviewDtr = $permissions['dtr.review'];
        $assignedReviewer = $hasEmployee || $actor->isAdmin();
        $leaveIds = $employees->filter(fn ($employee) => $employee->user_id !== $actor->id
            && ($canReviewLeave || ($assignedReviewer && $employee->manager_user_id === $actor->id)))->pluck('user_id');
        $dtrIds = $employees->filter(fn ($employee) => $employee->user_id !== $actor->id
            && ($canReviewDtr || ($assignedReviewer && $employee->reviewer_user_id === $actor->id)))->pluck('user_id');
        $today = $now->toDateString();
        $acknowledgementUserIds = $employees->filter(fn ($employee) => $employee->user_id !== $actor->id && ($canReviewDtr
            || ($assignedReviewer && ($employee->manager_user_id === $actor->id || $employee->reviewer_user_id === $actor->id))))->pluck('user_id');
        $acknowledgements = HrDtrAcknowledgement::where('status', 'pending')
            ->whereHas('entry', fn ($q) => $q->whereIn('user_id', $acknowledgementUserIds))
            ->when(! $canReviewDtr, fn ($q) => $q->where('head_user_id', $actor->id));
        $pending = DtrLeave::whereIn('user_id', $leaveIds)->whereIn('status', ['pending', 'deferred']);
        $reviews = ['leave' => (clone $pending)->count(), 'overdue_leave' => (clone $pending)->where('response_due', '<', $today)->count(),
            'dtr' => DtrEntry::whereIn('user_id', $dtrIds)->where('hr_status', 'submitted')->count(),
            'acknowledgements' => $acknowledgements->count()];
        $absences = DtrLeave::with('user:id,name')->whereIn('user_id', $leaveIds)->where('status', 'approved')
            ->whereDate('end_date', '>=', $today)->whereDate('start_date', '<=', $now->copy()->addDays(30)->toDateString())
            ->orderBy('start_date')->limit(50)->get()->map(fn ($leave) => ['id' => $leave->id, 'name' => $leave->user?->name,
                'start_date' => $leave->start_date->toDateString(), 'end_date' => $leave->end_date->toDateString(), 'half_day' => $leave->half_day]);

        $summary = $members->countBy('status')->all() + ['working' => 0, 'on_break' => 0, 'off_shift' => 0];

        return ['members' => $members, 'asOf' => $now->toISOString(), 'reviews' => $reviews, 'absences' => $absences, 'summary' => $summary];
    }
}
