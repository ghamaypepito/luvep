<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\DtrLeave;
use App\Models\HrEmployee;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use App\Services\Hr\MilestoneService;
use App\Services\Hr\WorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CalendarController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['date' => 'nullable|date_format:Y-m-d', 'view' => 'nullable|in:month,week,day']);
        $access = app(HrAccess::class);
        $context = app(HrContext::class);
        $preview = $context->isPreview($request);
        $actor = $request->user();
        $employee = $preview ? $context->subject($request) : $access->employee($actor);
        $subject = $preview ? $employee->user : $actor;
        $timezone = $employee?->schedule?->timezone ?? config('app.timezone');
        $date = CarbonImmutable::parse($data['date'] ?? now($timezone)->toDateString(), $timezone)->startOfDay();
        $view = $data['view'] ?? 'month';
        $start = match ($view) {
            'month' => $date->startOfMonth()->startOfWeek(),'week' => $date->startOfWeek(),default => $date
        };
        $end = match ($view) {
            'month' => $date->endOfMonth()->endOfWeek(),'week' => $date->endOfWeek(),default => $date
        };
        $ids = collect([$employee?->user_id])->filter();
        if (! $preview) {
            $scope = HrEmployee::where('status', 'active');
            if (! $access->allows($actor, 'leave.review') && ! $access->allows($actor, 'leave.fallback')) {
                $scope->where('manager_user_id', $actor->id);
            }
            $ids = $ids->merge($scope->pluck('user_id'))->unique();
        }
        $events = DtrLeave::with('user:id,name')->whereIn('user_id', $ids)->whereIn('status', ['pending', 'approved', 'deferred'])->whereDate('start_date', '<=', $end->toDateString())->whereDate('end_date', '>=', $start->toDateString())->orderBy('start_date')->get()->map(fn ($l) => ['id' => 'leave-'.$l->id, 'kind' => 'leave', 'title' => ($l->user?->name ?? 'Employee').' · '.ucfirst($l->status).' leave', 'start' => $l->start_date->toDateString(), 'end' => $l->end_date->toDateString(), 'detail' => $l->half_day ? 'Half day: '.$l->half_day : 'All day'])->all();
        foreach (app(MilestoneService::class)->visible($subject, ! $preview && $access->allows($actor, 'operations.manage'))->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])->whereNull('completed_at')->get() as $m) {
            $events[] = ['id' => 'milestone-'.$m->id, 'kind' => 'deadline', 'title' => $m->title, 'start' => $m->due_date->toDateString(), 'end' => $m->due_date->toDateString(), 'detail' => 'HR milestone'];
        }
        if ($employee?->schedule?->is_complete) {
            for ($day = $start; $day <= $end; $day = $day->addDay()) {
                $d = app(WorkingCalendar::class)->day($employee, $day->toDateString());
                if ($d['holiday'] || $d['working']) {
                    $events[] = ['id' => 'schedule-'.$d['date'], 'kind' => $d['holiday'] ? 'holiday' : 'schedule', 'title' => $d['holiday'] ?: 'My work schedule', 'start' => $d['date'], 'end' => $d['date'], 'detail' => $d['holiday'] ? 'Configured public holiday' : (($d['schedule']['in'] ?? 'Flexible').' – '.($d['schedule']['out'] ?? 'Flexible')).' · '.$timezone];
                }
            }
        }

        return Inertia::render('hr/Calendar', ['events' => $events, 'date' => $date->toDateString(), 'view' => $view, 'start' => $start->toDateString(), 'end' => $end->toDateString(), 'timezone' => $timezone]);
    }
}
