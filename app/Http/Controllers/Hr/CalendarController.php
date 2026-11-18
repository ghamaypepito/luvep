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
                $scope->where('manager_user_id', ($employee || $actor->isAdmin()) ? $actor->id : 0);
            }
            $ids = $ids->merge($scope->pluck('user_id'))->unique();
        }
        $leaves = DtrLeave::with('user:id,name')->whereIn('user_id', $ids)->whereIn('status', ['pending', 'approved', 'deferred'])->whereDate('start_date', '<=', $end->toDateString())->whereDate('end_date', '>=', $start->toDateString())->orderBy('start_date')->get();
        $events = $leaves->map(fn ($l) => ['id' => 'leave-'.$l->id, 'kind' => 'leave', 'title' => ($l->user?->name ?? 'Employee').' · '.ucfirst($l->status).' leave', 'start' => $l->start_date->toDateString(), 'end' => $l->end_date->toDateString(), 'detail' => in_array($l->half_day, ['AM', 'PM'], true) ? 'Half day: '.$l->half_day : 'All day'])->all();
        foreach (app(MilestoneService::class)->visible($subject, ! $preview && $access->allows($actor, 'operations.manage'))->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])->whereNull('completed_at')->get() as $m) {
            $events[] = ['id' => 'milestone-'.$m->id, 'kind' => 'deadline', 'title' => $m->title, 'start' => $m->due_date->toDateString(), 'end' => $m->due_date->toDateString(), 'detail' => 'HR milestone'];
        }
        if ($employee?->schedule?->is_complete) {
            for ($day = $start; $day <= $end; $day = $day->addDay()) {
                $d = app(WorkingCalendar::class)->day($employee, $day->toDateString());
                // Overlay leave on this date, without changing the recurring schedule.
                $approved = $leaves->filter(fn ($leave) => $leave->user_id === $employee->user_id
                    && $leave->status === 'approved'
                    && $leave->start_date->toDateString() <= $d['date']
                    && $leave->end_date->toDateString() >= $d['date']);
                $halves = $approved->pluck('half_day');
                $fullDay = $approved->contains(fn ($leave) => ! in_array($leave->half_day, ['AM', 'PM'], true))
                    || ($halves->contains('AM') && $halves->contains('PM'));
                $detail = (($d['schedule']['in'] ?? 'Flexible').' – '.($d['schedule']['out'] ?? 'Flexible')).' · '.$timezone;
                if ($d['working'] && $fullDay) {
                    $d['working'] = false;
                } elseif ($d['working'] && $approved->isNotEmpty()) {
                    $morningLeave = $halves->contains('AM');
                    $setting = $employee->schedule;
                    if ($setting->schedule_type === 'flexi' || ! $d['schedule']['in'] || ! $d['schedule']['out']) {
                        $detail = 'Half-day work remaining · Flexible · '.$timezone;
                    } else {
                        $in = CarbonImmutable::parse($d['date'].' '.$d['schedule']['in'], $timezone);
                        $out = CarbonImmutable::parse($d['date'].' '.$d['schedule']['out'], $timezone);
                        if ($out <= $in) {
                            $out = $out->addDay();
                        }
                        // Split net scheduled hours, leaving the regular break between halves.
                        $minutes = (int) round(max(0, $in->diffInMinutes($out) - (float) $setting->break_hours * 60) / 2);
                        $detail = ($morningLeave ? $out->subMinutes($minutes)->format('H:i') : $in->format('H:i'))
                            .' – '.($morningLeave ? $out->format('H:i') : $in->addMinutes($minutes)->format('H:i')).' · '.$timezone;
                    }
                }
                if ($d['holiday'] || $d['working']) {
                    $events[] = ['id' => 'schedule-'.$d['date'], 'kind' => $d['holiday'] ? 'holiday' : 'schedule', 'title' => $d['holiday'] ?: 'My work schedule', 'start' => $d['date'], 'end' => $d['date'], 'detail' => $d['holiday'] ? 'Configured public holiday' : $detail];
                }
            }
        }

        return Inertia::render('hr/Calendar', ['events' => $events, 'date' => $date->toDateString(), 'view' => $view, 'start' => $start->toDateString(), 'end' => $end->toDateString(), 'timezone' => $timezone]);
    }
}
