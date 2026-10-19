<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\DtrSetting;
use App\Models\HrEmployee;
use App\Models\User;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrAudit;
use App\Services\Hr\HrContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EnrollmentController extends Controller
{
    public function create(Request $request)
    {
        $this->authorizeSetup($request, 'enroll');

        return Inertia::render('hr/EmployeeSetup', ['employee' => null, 'candidates' => User::whereIn('role', config('hr.internal_roles'))->where('is_active', true)->whereNotIn('id', HrEmployee::pluck('user_id'))->orderBy('name')->get(['id', 'name', 'email']), 'reviewers' => [], 'timezones' => []]);
    }

    public function store(Request $request)
    {
        $this->authorizeSetup($request, 'enroll');
        $data = $request->validate(['user_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->whereIn('role', config('hr.internal_roles'))->where('is_active', true)->whereNull('deleted_at')), Rule::unique('hr_employees', 'user_id')], 'role' => 'prohibited', 'module_permissions' => 'prohibited', 'status' => 'prohibited']);
        $employee = DB::transaction(function () use ($request, $data) {
            $e = HrEmployee::create([...$data, 'status' => 'candidate']);
            app(HrAudit::class)->record($request->user(), 'employee.enrolled', $e);

            return $e;
        });

        return $request->expectsJson() ? response()->json(['employee' => $employee->only(['id', 'status'])], 201) : redirect(app(HrContext::class)->url('people/'.$employee->id.'/edit'));
    }

    public function edit(Request $request, HrEmployee $employee)
    {
        $this->authorizeSetup($request, 'profile.manage');
        $reviewers = User::whereIn('role', config('hr.internal_roles'))->where('is_active', true)->where('id', '!=', $employee->user_id)->where(fn ($q) => $q->whereIn('role', ['admin', 'super_admin'])->orWhereIn('id', HrEmployee::where('status', 'active')->pluck('user_id')))->orderBy('name')->get(['id', 'name']);

        return Inertia::render('hr/EmployeeSetup', ['employee' => [...$employee->only(['id', 'user_id', 'employee_number', 'status', 'department', 'position', 'office', 'employment_type', 'employment_start', 'employment_end', 'manager_user_id', 'reviewer_user_id', 'version']), 'name' => $employee->user->name, 'schedule' => $employee->schedule?->only(['timezone', 'team', 'schedule_type', 'sched_in', 'sched_out', 'weekly_schedule'])], 'reviewers' => $reviewers, 'timezones' => timezone_identifiers_list(), 'candidates' => []]);
    }

    public function update(Request $request, HrEmployee $employee)
    {
        $this->authorizeSetup($request, 'profile.manage');
        $data = $request->validate(['version' => 'required|integer|min:0', 'status' => 'required|in:candidate,active,archived', 'employee_number' => ['nullable', 'string', 'max:80', Rule::unique('hr_employees', 'employee_number')->ignore($employee->id)], 'department' => 'nullable|string|max:160', 'position' => 'nullable|string|max:160', 'office' => 'nullable|string|max:160', 'employment_type' => 'nullable|string|max:100', 'employment_start' => 'nullable|date_format:Y-m-d', 'employment_end' => 'nullable|date_format:Y-m-d|after_or_equal:employment_start', 'manager_user_id' => 'nullable|integer', 'reviewer_user_id' => 'nullable|integer', 'timezone' => ['nullable', Rule::in(timezone_identifiers_list())], 'team' => 'nullable|in:Philippines,New Zealand', 'schedule_type' => 'nullable|in:fixed,flexi', 'sched_in' => 'nullable|date_format:H:i', 'sched_out' => 'nullable|date_format:H:i', 'apply_default_hours' => 'sometimes|boolean', 'working_days' => 'nullable|array|min:1', 'working_days.*' => Rule::in(DtrSetting::WEEK_DAYS), 'role' => 'prohibited', 'module_permissions' => 'prohibited', 'user_id' => 'prohibited', 'emergency_contact' => 'prohibited']);
        $employee = DB::transaction(function () use ($request, $employee, $data) {
            $accessAccounts = $data['status'] === 'archived' ? User::orderBy('id')->lockForUpdate()->get() : collect();
            $employee = HrEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if ($data['status'] === 'archived' && app(HrAccess::class)->allows($employee->user, 'access.manage')) {
                abort_unless($accessAccounts->contains(fn ($u) => $u->id !== $employee->user_id && app(HrAccess::class)->allows($u, 'access.manage')), 409, 'Keep another active HR access administrator before archiving this account.');
            }
            abort_unless($employee->version === (int) $data['version'], 409, 'This employee record changed. Refresh before saving.');
            foreach (['manager_user_id', 'reviewer_user_id'] as $key) {
                if (! empty($data[$key])) {
                    $user = User::find($data[$key]);
                    $access = app(HrAccess::class);
                    if (! $user || $user->id === $employee->user_id || ! $access->internal($user) || (! $access->employee($user) && ! $user->isAdmin())) {
                        throw ValidationException::withMessages([$key => 'Choose another active employee or administrator.']);
                    }
                }
            }
            $setting = DtrSetting::where('user_id', $employee->user_id)->first();
            $scheduleKeys = ['timezone', 'team', 'schedule_type', 'sched_in', 'sched_out'];
            $schedule = array_intersect_key($data, array_flip($scheduleKeys));
            if (isset($data['working_days'])) {
                $schedule['weekly_schedule'] = collect(DtrSetting::WEEK_DAYS)->mapWithKeys(function ($day) use ($data, $setting) {
                    $previous = ($data['apply_default_hours'] ?? false) ? [] : ($setting?->weekly_schedule[$day] ?? []);

                    return [$day => ['on' => in_array($day, $data['working_days'], true), 'in' => $previous['in'] ?? $data['sched_in'] ?? $setting?->sched_in, 'out' => $previous['out'] ?? $data['sched_out'] ?? $setting?->sched_out]];
                })->all();
            }
            if ($schedule) {
                $setting ??= new DtrSetting(['user_id' => $employee->user_id]);
                $setting->fill($schedule);
                $setting->is_complete = (bool) ($setting->timezone && $setting->team && $setting->schedule_type && ($setting->schedule_type === 'flexi' || ($setting->sched_in && $setting->sched_out)));
                $setting->save();
            }
            $fields = array_diff_key($data, array_flip([...$scheduleKeys, 'working_days', 'apply_default_hours', 'version']));
            $employee->fill($fields);
            if ($employee->status === 'active' && (! $setting?->is_complete || ! $employee->manager_user_id || ! $employee->reviewer_user_id)) {
                throw ValidationException::withMessages(['status' => 'Complete the schedule and assign a Department Head and DTR reviewer before activation.']);
            }
            if ($employee->status === 'archived' && \App\Models\HrShift::where('hr_employee_id', $employee->id)->whereNull('ended_at')->exists()) {
                throw ValidationException::withMessages(['status' => 'Close or review the employee’s open shift before archiving.']);
            }
            $employee->version++;
            $employee->save();
            app(HrAudit::class)->record($request->user(), 'employee.updated', $employee, ['fields' => array_keys($fields), 'version' => $employee->version]);

            return $employee;
        });

        return $request->expectsJson() ? response()->json(['employee' => $employee->only(['id', 'status', 'version'])]) : back()->with('success', 'Employee setup saved.');
    }

    private function authorizeSetup(Request $request, string $ability): void
    {
        abort_unless(! app(HrContext::class)->isPreview($request) && app(HrAccess::class)->allows($request->user(), $ability), 403);
    }
}
