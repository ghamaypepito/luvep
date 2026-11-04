<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\DtrEntry;
use App\Models\HrDtrRevision;
use App\Services\Hr\DtrHistory;
use App\Services\Hr\HrContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DtrHistoryController extends Controller
{
    public function index(Request $request, DtrHistory $history)
    {
        abort_if(app(HrContext::class)->isPreview($request), 403);
        $team = $history->team($request->user());
        abort_unless((clone $team)->exists() || app(\App\Services\Hr\HrAccess::class)->allows($request->user(), 'dtr.review'), 403);
        $records = DtrEntry::with('user:id,name')->whereIn('user_id', $team->select('user_id'))
            ->orderByDesc('work_date')->orderByDesc('id')->paginate(30)
            ->through(fn ($entry) => ['id' => $entry->id, 'employee_name' => $entry->user?->name,
                'work_date' => $entry->work_date->toDateString(), 'hr_status' => $entry->hr_status,
                'hr_version' => $entry->hr_version, 'submitted_at' => $entry->submitted_at]);

        return Inertia::render('hr/DtrChanges', compact('records'))->toResponse($request)
            ->header('Cache-Control', 'private, no-store');
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
