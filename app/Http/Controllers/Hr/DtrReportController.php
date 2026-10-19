<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\DtrEntry;
use App\Models\HrEmployee;
use App\Models\HrWeeklySignoff;
use App\Services\Hr\DtrService;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrAudit;
use App\Services\Hr\HrContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DtrReportController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['date' => 'required_without:week|date_format:Y-m-d', 'week' => 'required_without:date|date_format:Y-m-d', 'employee' => 'nullable|integer']);
        abort_if($request->filled('date') && $request->filled('week'), 422, 'Choose a day or a week.');
        $context = app(HrContext::class);
        $actor = $request->user();
        $employee = isset($data['employee']) ? HrEmployee::findOrFail($data['employee']) : $context->subject($request);
        if ($context->isPreview($request)) {
            abort_unless($employee->id === $context->subject($request)->id, 404);
        } elseif ($employee->user_id !== $actor->id) {
            abort_unless(app(HrAccess::class)->allows($actor, 'dtr.review', $employee), 404);
        }
        $weekly = isset($data['week']);
        $start = CarbonImmutable::parse($data['week'] ?? $data['date']);
        abort_if($weekly && $start->dayOfWeekIso !== 1, 422, 'Select a Monday.');
        $end = $weekly ? $start->addDays(6) : $start;
        $entries = DtrEntry::where('user_id', $employee->user_id)->whereDate('work_date', '>=', $start->toDateString())->whereDate('work_date', '<=', $end->toDateString())->orderBy('work_date')->get()->map(fn ($e) => app(DtrService::class)->row($e));
        $signoff = $weekly ? HrWeeklySignoff::where('hr_employee_id', $employee->id)->whereDate('week_start', $start->toDateString())->first() : null;
        app(HrAudit::class)->record($actor, 'dtr.report_viewed', $employee, ['employee_id' => $employee->id]);

        return response()->view('hr.dtr-report', compact('employee', 'entries', 'weekly', 'start', 'end', 'signoff'))->header('Cache-Control','private, no-store')->header('X-Content-Type-Options','nosniff');
    }
}
