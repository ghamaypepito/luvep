<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\DtrEntry;
use App\Models\DtrLeave;
use App\Models\HrEmployee;
use App\Models\HrShift;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TeamController extends Controller
{
    public function __invoke(Request $request)
    {
        $access = app(HrAccess::class);
        $actor = $request->user();
        abort_if(app(HrContext::class)->isPreview($request), 403);
        abort_unless($access->canViewStaffStatus($actor), 403);

        $global = $access->allows($actor, 'dtr.review') || $access->allows($actor, 'reports');
        $employees = HrEmployee::with(['user:id,name', 'schedule'])
            ->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->where('is_active', true)->whereIn('role', config('hr.internal_roles')))
            ->whereDoesntHave('schedule', fn ($q) => $q->whereNotNull('archived_at'))
            ->when(! $global, fn ($q) => $q->where('user_id', '!=', $actor->id)->where(fn ($q) => $q->where('manager_user_id', $actor->id)->orWhere('reviewer_user_id', $actor->id)))
            ->get();
        $shifts = HrShift::with(['breaks' => fn ($q) => $q->whereNull('ended_at')->latest('started_at')])
            ->whereIn('hr_employee_id', $employees->pluck('id'))->whereNull('ended_at')->latest('started_at')->get()->unique('hr_employee_id')->keyBy('hr_employee_id');
        $members = $employees->sortBy(fn ($employee) => mb_strtolower($employee->user->name))->map(function ($employee) use ($shifts) {
            $shift = $shifts->get($employee->id);
            $break = $shift?->breaks->first();

            return ['id' => $employee->id, 'name' => $employee->user->name, 'department' => $employee->department,
                'status' => $break ? 'on_break' : ($shift ? 'working' : 'off_shift'),
                'started_at' => $shift?->started_at?->toISOString(), 'break_started_at' => $break?->started_at?->toISOString(),
                'timezone' => $shift?->timezone ?? $employee->schedule?->timezone ?? config('app.timezone', 'UTC')];
        })->values();

        $canReviewLeave = $access->allows($actor, 'leave.review') || $access->allows($actor, 'leave.fallback') || $access->allows($actor, 'leave.emergency');
        $canReviewDtr = $access->allows($actor, 'dtr.review');
        $assignedReviewer = $access->employee($actor) !== null || $actor->isAdmin();
        $leaveIds = $employees->filter(fn ($employee) => $employee->user_id !== $actor->id
            && ($canReviewLeave || ($assignedReviewer && $employee->manager_user_id === $actor->id)))->pluck('user_id');
        $dtrIds = $employees->filter(fn ($employee) => $employee->user_id !== $actor->id
            && ($canReviewDtr || ($assignedReviewer && $employee->reviewer_user_id === $actor->id)))->pluck('user_id');
        $today = now()->toDateString();
        $pending = DtrLeave::whereIn('user_id', $leaveIds)->whereIn('status', ['pending', 'deferred']);
        $reviews = ['leave' => (clone $pending)->count(), 'overdue_leave' => (clone $pending)->where('response_due', '<', $today)->count(),
            'dtr' => DtrEntry::whereIn('user_id', $dtrIds)->where('hr_status', 'submitted')->count()];
        $absences = DtrLeave::with('user:id,name')->whereIn('user_id', $leaveIds)->where('status', 'approved')
            ->whereDate('end_date', '>=', $today)->whereDate('start_date', '<=', now()->addDays(30)->toDateString())
            ->orderBy('start_date')->limit(50)->get()->map(fn ($leave) => ['id' => $leave->id, 'name' => $leave->user?->name,
                'start_date' => $leave->start_date->toDateString(), 'end_date' => $leave->end_date->toDateString(), 'half_day' => $leave->half_day]);

        return Inertia::render('hr/Team', ['members' => $members, 'asOf' => now()->toISOString(), 'reviews' => $reviews, 'absences' => $absences]);
    }
}
