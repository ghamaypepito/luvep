<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\DtrEntry;
use App\Models\DtrLeave;
use App\Models\HrEmployee;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $context = app(HrContext::class);
        $actor = $request->user();
        $access = app(HrAccess::class);
        $preview = $context->isPreview($request);
        $employee = $preview ? $context->subject($request) : $access->employee($actor);
        $today = now($employee?->schedule?->timezone ?? 'UTC')->toDateString();
        $upcoming = [];
        $own = ['pending_leave' => 0, 'returned_dtr' => 0, 'submitted_dtr' => 0];
        if ($employee) {
            $leaves = DtrLeave::where('user_id', $employee->user_id);
            $own['pending_leave'] = (clone $leaves)->whereIn('status', ['pending', 'deferred'])->count();
            $upcoming = (clone $leaves)->whereIn('status', ['pending', 'approved', 'deferred'])->whereDate('end_date', '>=', $today)->orderBy('start_date')->limit(5)->get()->map(fn ($l) => ['id' => $l->id, 'start_date' => $l->start_date->toDateString(), 'end_date' => $l->end_date->toDateString(), 'status' => $l->status, 'total_days' => $l->total_days]);
            $own['returned_dtr'] = DtrEntry::where('user_id', $employee->user_id)->where('hr_status', 'returned')->count();
            $own['submitted_dtr'] = DtrEntry::where('user_id', $employee->user_id)->where('hr_status', 'submitted')->count();
        }
        $review = ['leave' => 0, 'overdue_leave' => 0, 'dtr' => 0];
        $absences = [];
        if (! $preview) {
            $leaveScope = HrEmployee::where('user_id', '!=', $actor->id);
            if (! $access->allows($actor, 'leave.review') && ! $access->allows($actor, 'leave.fallback') && ! $access->allows($actor, 'leave.emergency')) {
                $leaveScope->where('manager_user_id', $actor->id);
            }
            $leaveIds = $leaveScope->pluck('user_id');
            $pending = DtrLeave::whereIn('user_id', $leaveIds)->whereIn('status', ['pending', 'deferred']);
            $review['leave'] = (clone $pending)->count();
            $review['overdue_leave'] = (clone $pending)->where('response_due', '<', $today)->count();
            $dtrScope = HrEmployee::where('user_id', '!=', $actor->id);
            if (! $access->allows($actor, 'dtr.review')) {
                $dtrScope->where('reviewer_user_id', $actor->id);
            }$review['dtr'] = DtrEntry::whereIn('user_id', $dtrScope->pluck('user_id'))->where('hr_status', 'submitted')->count();
            $absences = DtrLeave::with('user:id,name')->whereIn('user_id', $leaveIds)->where('status', 'approved')->whereDate('end_date', '>=', $today)->whereDate('start_date', '<=', now()->addDays(30)->toDateString())->orderBy('start_date')->limit(50)->get()->map(fn ($l) => ['id' => $l->id, 'name' => $l->user?->name, 'start_date' => $l->start_date->toDateString(), 'end_date' => $l->end_date->toDateString(), 'half_day' => $l->half_day]);
        }

        $milestoneUser = $preview ? $employee->user : $actor;
        $dueMilestones = app(\App\Services\Hr\MilestoneService::class)->visible($milestoneUser, ! $preview && $access->allows($actor, 'operations.manage'))->with('employee.schedule')->whereNull('completed_at')->get()->filter(fn ($m) => $m->due_date->toDateString() <= now($m->employee->schedule?->timezone ?? 'UTC')->toDateString())->count();

        return Inertia::render('hr/Dashboard', ['dueMilestones' => $dueMilestones, 'profile' => EmployeeController::profileData($employee, $employee?->user ?? $actor), 'ownSummary' => $own, 'upcomingLeave' => $upcoming, 'reviews' => $review, 'absences' => $absences]);
    }
}
