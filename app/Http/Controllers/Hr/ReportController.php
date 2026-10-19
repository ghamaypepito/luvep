<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrExport;
use App\Services\Hr\HrAudit;
use App\Services\Hr\HrContext;
use App\Services\Hr\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function index(Request $r)
    {
        $c = app(HrContext::class);
        $preview = $c->isPreview($r);
        $actor = $preview ? $c->subject($r)->user : $r->user();
        $s = app(ReportService::class);
        $type = $r->input('type', 'attendance');
        $filters = $s->filters($type, ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString(), ...$r->only(['from', 'to', 'employee_id', 'department', 'status'])]);
        if ($preview) {
            $subject = $c->subject($r);
            abort_if(isset($filters['employee_id']) && (int) $filters['employee_id'] !== $subject->id, 404);
            abort_unless($s->employees($r->user(), $type)->contains('id', $subject->id), 404);
            $filters['employee_id'] = $subject->id;
        }
        $rows = $s->rows($actor, $type, $filters)->take(501)->values()->all();
        $truncated = count($rows) > 500;
        $rows = array_slice($rows, 0, 500);
        $employees = $s->employees($actor, $type)->when($preview, fn ($items) => $items->where('id', $c->subject($r)->id))->values()->map(fn ($e) => ['id' => $e->id, 'name' => $e->user->name, 'department' => $e->department]);
        $exports = $preview ? [] : HrExport::where('user_id', $actor->id)->latest('id')->limit(20)->get()->map(fn ($x) => ['id' => $x->id, 'type' => $x->type, 'status' => $x->status === 'ready' && ! $s->authorized($x) ? 'revoked' : $x->status, 'created_at' => $x->created_at, 'expires_at' => $x->expires_at]);

        return Inertia::render('hr/Reports', ['type' => $type, 'types' => $preview ? array_values(array_filter(ReportService::TYPES, fn ($t) => $s->employees($r->user(), $t)->contains('id', $c->subject($r)->id))) : ReportService::TYPES, 'filters' => $filters, 'rows' => $rows, 'truncated' => $truncated, 'employees' => $employees, 'exports' => $exports, 'preview' => $preview]);
    }

    public function store(Request $r)
    {
        $data = $r->validate(['type' => 'required|string']);
        $x = app(ReportService::class)->requestExport($r->user(), $data['type'], $r->all());

        return $r->expectsJson() ? response()->json(['id' => $x->id, 'status' => $x->status], 202) : back()->with('success', 'Your private export is queued.');
    }

    public function download(Request $r, HrExport $export)
    {
        abort_if(app(HrContext::class)->isPreview($r), 404);
        abort_unless($export->user_id === $r->user()->id && $export->status === 'ready' && app(ReportService::class)->authorized($export) && $export->storage_key && Storage::disk('hr_private')->exists($export->storage_key), 404);
        app(HrAudit::class)->record($r->user(), 'report.export_downloaded', $export);

        return Storage::disk('hr_private')->download($export->storage_key, 'luvep-'.$export->type.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
