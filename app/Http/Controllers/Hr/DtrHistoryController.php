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
        $records = DtrEntry::with('user:id,name')->whereIn('user_id', $team->select('user_id'))
            ->orderByDesc('work_date')->orderByDesc('id')->paginate(30)
            ->through(fn ($entry) => ['id' => $entry->id, 'employee_name' => $entry->user?->name,
                'work_date' => $entry->work_date->toDateString(), 'hr_status' => $entry->hr_status,
                'hr_version' => $entry->hr_version, 'submitted_at' => $entry->submitted_at]);

        $acknowledgements = HrDtrAcknowledgement::with('entry.user', 'head')
            ->whereHas('entry', fn ($q) => $q->whereIn('user_id', (clone $team)->select('user_id')))
            ->when(! app(\App\Services\Hr\HrAccess::class)->allows($request->user(), 'dtr.review'), fn ($q) => $q->where('head_user_id', $request->user()->id))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(200)->get()
            ->map(fn ($ack) => $history->acknowledgementRow($ack, $request->user()));

        return Inertia::render('hr/DtrChanges', ['mode' => 'team', 'records' => $records, 'acknowledgements' => $acknowledgements])->toResponse($request)
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

        return response()->json(['history' => $revisions])->header('Cache-Control', 'private, no-store');
    }
}
