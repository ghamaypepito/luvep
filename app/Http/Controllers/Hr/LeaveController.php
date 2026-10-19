<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\DtrLeave;
use App\Models\HrEmployee;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use App\Services\Hr\LeaveService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeaveController extends Controller
{
    public function index(Request $request)
    {
        $employee = app(HrContext::class)->subject($request);
        $leaves = DtrLeave::where('user_id', $employee->user_id)->orderByDesc('id')->limit(100)->get()->map(fn ($l) => $this->row($l));

        return Inertia::render('hr/Leave', ['leaves' => $leaves, 'types' => DtrLeave::TYPES, 'manager' => $employee->manager?->name, 'today' => now($employee->schedule?->timezone ?? 'UTC')->toDateString()]);
    }

    public function inbox(Request $request)
    {
        $actor = $request->user();
        $access = app(HrAccess::class);
        abort_if(app(HrContext::class)->isPreview($request), 403);
        $all = $access->allows($actor, 'leave.review') || $access->allows($actor, 'leave.fallback') || $access->allows($actor, 'leave.emergency');
        $scope = HrEmployee::where('user_id', '!=', $actor->id);
        if (! $all) {
            $scope->where('manager_user_id', $actor->id);
        }
        $ids = $scope->pluck('user_id');
        abort_unless($access->internal($actor) && ($all || $ids->isNotEmpty()), 403);
        $leaves = DtrLeave::with('user')->whereIn('user_id', $ids)->whereIn('status', ['pending', 'deferred'])->orderBy('created_at')->limit(200)->get()->map(function ($l) use ($actor, $access) {
            $e = HrEmployee::where('user_id', $l->user_id)->first();

            return [...$this->row($l), 'employee_name' => $l->user->name, 'can_decide' => $access->allows($actor, 'leave.review', $e) || $access->allows($actor, 'leave.fallback', $e), 'can_emergency' => $access->allows($actor, 'leave.emergency', $e)];
        });

        return Inertia::render('hr/LeaveInbox', compact('leaves'));
    }

    public function print(Request $request, DtrLeave $leave)
    {
        $context = app(HrContext::class);
        $actor = $request->user();
        $access = app(HrAccess::class);
        $employee = HrEmployee::where('user_id', $leave->user_id)->firstOrFail();
        if ($context->isPreview($request)) {
            abort_unless($context->subject($request)->id === $employee->id, 404);
        } elseif ($actor->id === $leave->user_id) {
            abort_unless($access->employee($actor)?->id === $employee->id, 404);
        } else {
            abort_unless($access->allows($actor, 'leave.review', $employee) || $access->allows($actor, 'leave.fallback', $employee) || $access->allows($actor, 'leave.emergency', $employee), 404);
        }
        app(\App\Services\Hr\HrAudit::class)->record($actor, 'leave.print_viewed', $leave, ['employee_id' => $employee->id]);

        return response()->view('hr.leave-print', compact('employee', 'leave'))->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function store(Request $request)
    {
        $data = $request->validate(['request_id' => 'required|uuid']);
        $leave = app(LeaveService::class)->submit($request->user(), $request->all(), $data['request_id']);

        return $this->result($request, $leave);
    }

    public function decide(Request $request, DtrLeave $leave)
    {
        $data = $request->validate(['version' => 'required|integer|min:0', 'decision' => 'required|in:approved,rejected,deferred', 'comment' => 'nullable|string|max:5000']);

        return $this->result($request, app(LeaveService::class)->decide($request->user(), $leave, $data['decision'], $data['comment'] ?? '', (int) $data['version']));
    }

    public function emergency(Request $request, DtrLeave $leave)
    {
        $data = $request->validate(['version' => 'required|integer|min:0', 'comment' => 'required|string|max:5000']);

        return $this->result($request, app(LeaveService::class)->approveEmergency($request->user(), $leave, $data['comment'], (int) $data['version']));
    }

    public function cancel(Request $request, DtrLeave $leave)
    {
        $data = $request->validate(['version' => 'required|integer|min:0']);

        return $this->result($request, app(LeaveService::class)->cancel($request->user(), $leave, (int) $data['version']));
    }

    public function row(DtrLeave $leave): array
    {
        return [...$leave->only(['id', 'type', 'other_specify', 'total_days', 'half_day', 'status', 'decision', 'reason', 'manager_comments', 'hr_version', 'declared_name', 'employee_signed_at', 'sick_for', 'notified_via', 'notified_at', 'certificate_status', 'cover_person', 'handover', 'response_due', 'emergency_required', 'emergency_approved_at', 'created_at']), 'start_date' => $leave->start_date->toDateString(), 'end_date' => $leave->end_date->toDateString()];
    }

    private function result(Request $request, DtrLeave $leave)
    {
        return $request->expectsJson() ? response()->json(['leave' => $this->row($leave)]) : back()->with('success', 'Leave request saved.');
    }
}
