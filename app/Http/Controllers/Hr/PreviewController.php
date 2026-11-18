<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrEmployee;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrAudit;
use App\Services\Hr\HrContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PreviewController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(app(HrAccess::class)->allows($request->user(), 'preview'), 403);
        $search = mb_substr((string) $request->query('search', ''), 0, 100);
        $employees = HrEmployee::with('user')->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->where('is_active', true)->whereIn('role', config('hr.internal_roles')))
            ->whereDoesntHave('schedule', fn ($q) => $q->whereNotNull('archived_at'))
            ->when($search, fn ($q) => $q->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$search.'%')))
            ->paginate(25)->withQueryString()->through(fn ($e) => ['id' => $e->id, 'name' => $e->user->name, 'department' => $e->department]);

        return Inertia::render('hr/Preview', compact('employees', 'search'));
    }

    public function store(Request $request, HrEmployee $employee)
    {
        $access = app(HrAccess::class);
        abort_unless($access->allows($request->user(), 'preview', $employee), 403);
        abort_unless($employee->user && $access->employee($employee->user)?->id === $employee->id, 404);
        app(HrAudit::class)->record($request->user(), 'preview.started', $employee);
        $request->session()->put('hr_preview_employee_id', $employee->id);

        return redirect()->away(app(HrContext::class)->url());
    }

    public function destroy(Request $request)
    {
        $employee = HrEmployee::find($request->session()->pull('hr_preview_employee_id'));
        if ($employee) {
            app(HrAudit::class)->record($request->user(), 'preview.ended', $employee);
        }

        return redirect()->away(app(HrContext::class)->url());
    }
}
