<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\DtrEntry;
use App\Models\HrEmployee;
use App\Models\HrWeeklySignoff;
use App\Services\Hr\DtrService;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DtrController extends Controller
{
    public function index(Request $request)
    {
        $employee = app(HrContext::class)->subject($request);
        $entries = DtrEntry::where('user_id', $employee->user_id)->orderByDesc('work_date')->limit(60)->get()->map(fn ($e) => app(DtrService::class)->row($e));
        $weeks = HrWeeklySignoff::where('hr_employee_id', $employee->id)->orderByDesc('week_start')->limit(20)->get();

        return Inertia::render('hr/Dtr', ['entries' => $entries, 'weeks' => $weeks, 'today' => now($employee->schedule?->timezone ?? 'UTC')->toDateString()]);
    }

    public function store(Request $request)
    {
        return $this->result($request, app(DtrService::class)->saveDraft(app(HrContext::class)->subject($request), $request->all()));
    }

    public function submit(Request $request, DtrEntry $entry)
    {
        $data = $request->validate(['version' => 'required|integer|min:0', 'declaration' => 'required|accepted']);

        return $this->result($request, app(DtrService::class)->submit($request->user(), $entry, (int) $data['version']));
    }

    public function review(Request $request, DtrEntry $entry)
    {
        $data = $request->validate(['version' => 'required|integer|min:0', 'decision' => 'required|in:reviewed,returned', 'comment' => 'nullable|string|max:5000']);

        return $this->result($request, app(DtrService::class)->review($request->user(), $entry, $data['decision'], $data['comment'] ?? '', (int) $data['version']));
    }

    public function corrections(Request $request)
    {
        abort_if(app(HrContext::class)->isPreview($request), 403);
        abort_unless(app(HrAccess::class)->allows($request->user(), 'dtr.correct'), 403);
        $filters = $request->validate(['employee_id' => 'nullable|integer|exists:hr_employees,id', 'date' => 'nullable|date_format:Y-m-d']);
        $employees = HrEmployee::with('user')->get()->map(fn ($e) => ['id' => $e->id, 'name' => $e->user?->name]);
        $record = null;
        if (! empty($filters['employee_id']) && ! empty($filters['date'])) {
            $employee = HrEmployee::findOrFail($filters['employee_id']);
            $entry = DtrEntry::where('user_id', $employee->user_id)->whereDate('work_date', $filters['date'])->first();
            if ($entry) {
                $shifts = \App\Models\HrShift::with('breaks')->where('hr_employee_id', $employee->id)->whereDate('work_date', $filters['date'])->orderBy('started_at')->get();
                $record = [...app(DtrService::class)->row($entry), 'employee_name' => $employee->user->name, 'timezone' => $employee->schedule?->timezone ?? 'UTC', 'can_correct' => $shifts->isNotEmpty() && ! $shifts->contains(fn ($s) => ! $s->ended_at), 'shifts' => $shifts->map(fn ($s) => ['shift_id' => $s->id, 'started_at' => $s->started_at->utc()->format('Y-m-d\TH:i:s'), 'ended_at' => $s->ended_at?->utc()->format('Y-m-d\TH:i:s'), 'breaks' => $s->breaks->map(fn ($b) => ['started_at' => $b->started_at->utc()->format('Y-m-d\TH:i:s'), 'ended_at' => $b->ended_at?->utc()->format('Y-m-d\TH:i:s')])])];
            }
        }

        return Inertia::render('hr/DtrCorrections', compact('employees', 'record', 'filters'));
    }

    public function correct(Request $request, DtrEntry $entry)
    {
        $data = $request->validate(['version' => 'required|integer|min:0', 'reason' => 'required|string|max:5000', 'intervals' => 'required|array|min:1']);

        return $this->result($request, app(DtrService::class)->correct($request->user(), $entry, $data['intervals'], $data['reason'], (int) $data['version']));
    }

    public function signWeek(Request $request)
    {
        $data = $request->validate(['week_start' => 'required|date_format:Y-m-d', 'declaration' => 'required|accepted']);
        $week = app(DtrService::class)->signWeek($request->user(), app(HrContext::class)->subject($request), $data['week_start']);

        return $request->expectsJson() ? response()->json(['week' => $week]) : back()->with('success', 'Weekly DTR submitted.');
    }

    public function reviewWeek(Request $request, HrWeeklySignoff $week)
    {
        $data = $request->validate(['version' => 'required|integer|min:0', 'decision' => 'required|in:reviewed,returned', 'comment' => 'nullable|string|max:5000']);
        $week = app(DtrService::class)->reviewWeek($request->user(), $week, $data['decision'], $data['comment'] ?? '', (int) $data['version']);

        return $request->expectsJson() ? response()->json(['week' => $week]) : back()->with('success', 'Weekly review recorded.');
    }

    public function inbox(Request $request)
    {
        $actor = $request->user();
        $access = app(HrAccess::class);
        abort_if(app(HrContext::class)->isPreview($request), 403);
        $scope = HrEmployee::where('user_id', '!=', $actor->id);
        if (! $access->allows($actor, 'dtr.review')) {
            $scope->where('reviewer_user_id', ($access->employee($actor) || $actor->isAdmin()) ? $actor->id : 0);
        }
        $ids = $scope->pluck('user_id');
        abort_unless($access->internal($actor) && ($access->allows($actor, 'dtr.review') || $ids->isNotEmpty()), 403);
        $entries = DtrEntry::with('user')->whereIn('user_id', $ids)->where('hr_status', 'submitted')->orderBy('work_date')->limit(200)->get()
            ->map(fn ($e) => [...app(DtrService::class)->row($e), 'employee_name' => $e->user->name, 'employee_id' => HrEmployee::where('user_id', $e->user_id)->value('id')]);
        $weeks = HrWeeklySignoff::with('employee.user')->whereIn('hr_employee_id', (clone $scope)->pluck('id'))->where('status', 'submitted')->orderBy('week_start')->limit(100)->get()
            ->map(fn ($w) => [...$w->only(['id', 'week_start', 'version', 'status', 'signed_at']), 'employee_name' => $w->employee->user->name, 'employee_id' => $w->hr_employee_id]);

        return Inertia::render('hr/DtrReview', compact('entries', 'weeks'));
    }

    private function result(Request $request, DtrEntry $entry)
    {
        return $request->expectsJson() ? response()->json(['entry' => app(DtrService::class)->row($entry)]) : back()->with('success', 'Time record saved.');
    }
}
