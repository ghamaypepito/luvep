<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\DtrEntry;
use App\Models\DtrLeave;
use App\Models\HrDtrAcknowledgement;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use App\Services\Hr\TeamWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TeamWorkspace $workspace)
    {
        $context = app(HrContext::class);
        $actor = $request->user();
        $access = app(HrAccess::class);
        $preview = $context->isPreview($request);
        $employee = $preview ? $context->subject($request) : $access->employee($actor);
        $timezone = $employee?->schedule?->timezone ?? 'UTC';
        $now = now($timezone);
        $today = $now->toDateString();
        $upcoming = [];
        $own = ['pending_leave' => 0, 'returned_dtr' => 0, 'submitted_dtr' => 0, 'week_reports' => 0,
            'pending_changes' => 0, 'week_from' => $now->copy()->startOfWeek(CarbonInterface::MONDAY)->toDateString(), 'week_to' => $today];
        if ($employee) {
            $leaves = DtrLeave::where('user_id', $employee->user_id);
            $own['pending_leave'] = (clone $leaves)->whereIn('status', ['pending', 'deferred'])->count();
            $upcoming = (clone $leaves)->whereIn('status', ['pending', 'approved', 'deferred'])->whereDate('end_date', '>=', $today)->orderBy('start_date')->limit(5)->get()->map(fn ($l) => ['id' => $l->id, 'start_date' => $l->start_date->toDateString(), 'end_date' => $l->end_date->toDateString(), 'status' => $l->status, 'total_days' => $l->total_days]);
            $entries = DtrEntry::where('user_id', $employee->user_id);
            $own['returned_dtr'] = (clone $entries)->where('hr_status', 'returned')->count();
            $own['submitted_dtr'] = (clone $entries)->where('hr_status', 'submitted')->count();
            $own['week_reports'] = $entries->where('work_date', '>=', $own['week_from'])
                ->where('work_date', '<', $now->copy()->addDay()->toDateString())->count();
            $own['pending_changes'] = HrDtrAcknowledgement::where('status', 'pending')
                ->whereHas('entry', fn ($q) => $q->where('user_id', $employee->user_id))->count();
        }
        $milestoneUser = $preview ? $employee->user : $actor;
        $dueMilestones = app(\App\Services\Hr\MilestoneService::class)->visible($milestoneUser)->with('employee.schedule')->whereNull('completed_at')->get()->filter(fn ($m) => $m->due_date->toDateString() <= now($m->employee->schedule?->timezone ?? 'UTC')->toDateString())->count();

        $team = $preview ? null : $workspace->forActor($actor, $employee !== null);
        $mode = match (true) {
            ! $preview && $actor->isAdmin() => 'admin',
            $team !== null => 'head',
            default => 'employee',
        };

        return Inertia::render('hr/Dashboard', [
            'dueMilestones' => $dueMilestones,
            'profile' => EmployeeController::profileData($employee, $employee?->user ?? $actor),
            'ownSummary' => $own,
            'upcomingLeave' => $upcoming,
            'overview' => ['mode' => $mode, 'timezone' => $timezone, 'today' => $today, 'server_now' => $now->toISOString()],
            'teamOverview' => $team,
        ]);
    }
}
