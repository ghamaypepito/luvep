<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\DtrEntry;
use App\Models\DtrLeave;
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
        $milestoneUser = $preview ? $employee->user : $actor;
        $dueMilestones = app(\App\Services\Hr\MilestoneService::class)->visible($milestoneUser)->with('employee.schedule')->whereNull('completed_at')->get()->filter(fn ($m) => $m->due_date->toDateString() <= now($m->employee->schedule?->timezone ?? 'UTC')->toDateString())->count();

        return Inertia::render('hr/Dashboard', ['dueMilestones' => $dueMilestones, 'profile' => EmployeeController::profileData($employee, $employee?->user ?? $actor), 'ownSummary' => $own, 'upcomingLeave' => $upcoming]);
    }
}
