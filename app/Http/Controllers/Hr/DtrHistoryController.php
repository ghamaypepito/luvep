<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\DtrEntry;
use App\Models\HrDtrAcknowledgement;
use App\Models\HrDtrRevision;
use App\Services\Hr\DtrHistory;
use App\Services\Hr\DtrService;
use App\Services\Hr\HrContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DtrHistoryController extends Controller
{
    public function index(Request $request, DtrHistory $history)
    {
        abort_if(app(HrContext::class)->isPreview($request), 403);
        if ($request->query('scope') === 'mine') {
            $employee = app(HrContext::class)->subject($request);
            $entries = DtrEntry::where('user_id', $employee->user_id)->orderByDesc('work_date')->limit(60)->get()
                ->map(fn ($entry) => [...app(DtrService::class)->row($entry), 'report_baseline' => $history->reportBaseline($entry), 'report_changes' => $history->reportChanges($entry)]);
            $head = $history->departmentHead($employee);
            $departmentHead = $head ? $head->only(['id', 'name']) : null;
            $acknowledgements = HrDtrAcknowledgement::with('entry.user', 'head')->whereHas('entry', fn ($q) => $q->where('user_id', $employee->user_id))
                ->latest('id')->limit(200)->get()->map(fn ($ack) => $history->acknowledgementRow($ack, $request->user()));

            return Inertia::render('hr/DtrChanges', ['mode' => 'mine', 'entries' => $entries, 'departmentHead' => $departmentHead, 'acknowledgements' => $acknowledgements])->toResponse($request)
                ->header('Cache-Control', 'private, no-store');
        }
        $team = $history->team($request->user());
        abort_unless((clone $team)->exists() || app(\App\Services\Hr\HrAccess::class)->allows($request->user(), 'dtr.review'), 403);
        $filters = app(\App\Services\Hr\DtrCalendar::class)->filters($request);
        $employees = app(\App\Services\Hr\DtrCalendar::class)->employees($team->get(), $filters);
        $records = ['data' => app(\App\Services\Hr\DtrCalendar::class)->records($request->user(), $employees, $filters)];
        $oldestPending = app(\App\Services\Hr\DtrCalendar::class)->oldestPendingAcknowledgement($request->user(), $employees);

        return Inertia::render('hr/DtrChanges', ['mode' => 'team', 'records' => $records, 'filters' => $filters, 'oldestPending' => $oldestPending])->toResponse($request)
            ->header('Cache-Control', 'private, no-store');
    }

    public function saveReport(Request $request, DtrEntry $entry, DtrService $service, DtrHistory $history)
    {
        abort_if(app(HrContext::class)->isPreview($request), 403);
        $entry = $service->saveReport($request->user(), $entry, $request->all());

        return $request->expectsJson() ? response()->json(['entry' => [...$service->row($entry), 'report_baseline' => $history->reportBaseline($entry), 'report_changes' => $history->reportChanges($entry)]]) : back()->with('success', 'Report changes saved.');
    }

    public function submitAcknowledgement(Request $request, DtrEntry $entry, DtrService $service, DtrHistory $history)
    {
        abort_if(app(HrContext::class)->isPreview($request), 403);
        $data = $request->validate(['version' => 'required|integer|min:0', 'reason' => 'required|string|max:5000']);
        $acknowledgement = $service->submitAcknowledgement($request->user(), $entry, (int) $data['version'], $data['reason']);

        return $request->expectsJson() ? response()->json(['acknowledgement' => $history->acknowledgementRow($acknowledgement, $request->user())]) : back()->with('success', 'Changes sent for acknowledgement.');
    }

    public function decideAcknowledgement(Request $request, HrDtrAcknowledgement $acknowledgement, DtrService $service, DtrHistory $history)
    {
        abort_if(app(HrContext::class)->isPreview($request), 403);
        $data = $request->validate(['version' => 'required|integer|min:1', 'decision' => 'required|in:acknowledged,clarification', 'comment' => 'nullable|string|max:5000']);
        $acknowledgement = $service->decideAcknowledgement($request->user(), $acknowledgement, (int) $data['version'], $data['decision'], $data['comment'] ?? '');

        return $request->expectsJson() ? response()->json(['acknowledgement' => $history->acknowledgementRow($acknowledgement, $request->user())]) : back()->with('success', 'Acknowledgement recorded.');
    }

    public function show(Request $request, DtrEntry $entry, DtrHistory $history)
    {
        abort_if(app(HrContext::class)->isPreview($request), 403);
        abort_unless($history->canView($request->user(), $entry), 404);
        $revisions = HrDtrRevision::with('actor:id,name')->where('dtr_entry_id', $entry->id)
            ->orderByDesc('version')->paginate(20)->through(fn ($revision) => [
                'id' => $revision->id, 'version' => $revision->version, 'action' => $revision->action,
                'actor' => $revision->actor?->name ?? 'Former team member',
                'created_at' => $revision->created_at, 'changes' => $revision->changes,
            ]);

        $details = [];
        if ($request->boolean('details')) {
            $employee = \App\Models\HrEmployee::where('user_id', $entry->user_id)->firstOrFail();
            $acknowledgements = HrDtrAcknowledgement::with('entry.user', 'head')->where('dtr_entry_id', $entry->id)
                ->when($entry->user_id !== $request->user()->id && ! app(\App\Services\Hr\HrAccess::class)->allows($request->user(), 'dtr.review'), fn ($q) => $q->where('head_user_id', $request->user()->id))
                ->latest('id')->paginate(10, ['*'], 'ack_page')->through(fn ($ack) => $history->acknowledgementRow($ack, $request->user()));
            $details = ['entry' => [...app(DtrService::class)->row($entry),
                'employee_name' => $entry->user->name, 'employee_id' => $employee->id,
                'can_print' => $entry->user_id === $request->user()->id || app(\App\Services\Hr\HrAccess::class)->allows($request->user(), 'dtr.review', $employee),
                'can_review' => $entry->hr_status === 'submitted' && app(\App\Services\Hr\HrAccess::class)->allows($request->user(), 'dtr.review', $employee)],
                'acknowledgements' => $acknowledgements];
        }

        return response()->json(['history' => $revisions, ...$details])->header('Cache-Control', 'private, no-store');
    }
}
