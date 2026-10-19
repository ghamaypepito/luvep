<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\UpdatePersonalProfileRequest;
use App\Models\HrEmployee;
use App\Models\User;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrAudit;
use App\Services\Hr\HrContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    public static function profileData(?HrEmployee $employee, User $user): array
    {
        return ['id' => $employee?->id, 'user' => $user->only(['id', 'name', 'email', 'phone', 'avatar_url']),
            'employee_number' => $employee?->employee_number, 'department' => $employee?->department,
            'position' => $employee?->position, 'office' => $employee?->office, 'employment_type' => $employee?->employment_type,
            'employment_start' => $employee?->employment_start?->toDateString(), 'manager' => $employee?->manager?->name,
            'reviewer' => $employee?->reviewer?->name, 'emergency_contact' => $employee?->emergency_contact,
            'schedule' => $employee?->schedule?->only(['timezone', 'sched_in', 'sched_out', 'weekly_schedule', 'schedule_type'])];
    }

    public function profile(Request $request)
    {
        $context = app(HrContext::class);
        $employee = $context->isPreview($request) ? $context->subject($request) : app(HrAccess::class)->employee($request->user());

        return Inertia::render('hr/Profile', ['profile' => self::profileData($employee, $employee?->user ?? $request->user())]);
    }

    public function update(UpdatePersonalProfileRequest $request)
    {
        $actor = $request->user();
        $employee = app(HrAccess::class)->employee($actor);
        DB::transaction(function () use ($actor, $employee, $request) {
            $data = $request->validated();
            if (array_key_exists('phone', $data)) {
                $actor->update(['phone' => $data['phone']]);
            }
            if ($employee && array_key_exists('emergency_contact', $data)) {
                $employee->update(['emergency_contact' => $data['emergency_contact']]);
            }
            app(HrAudit::class)->record($actor, 'profile.updated', $employee ?? $actor, ['fields' => array_keys($data)]);
        });

        return back()->with('success', 'Your profile has been updated.');
    }

    public function index(Request $request)
    {
        abort_unless(app(HrAccess::class)->allows($request->user(), 'people'), 403);
        abort_if(app(HrContext::class)->isPreview($request), 403);
        $search = mb_substr((string) $request->query('search', ''), 0, 100);
        $employees = HrEmployee::with('user')->when($search, fn ($q) => $q->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$search.'%')))
            ->orderBy('id')->paginate(25)->withQueryString()->through(fn ($e) => ['id' => $e->id, 'name' => $e->user?->name, 'department' => $e->department, 'position' => $e->position, 'status' => $e->status]);

        return Inertia::render('hr/Employees', compact('employees', 'search'));
    }

    public function show(Request $request, HrEmployee $employee)
    {
        abort_unless(! app(HrContext::class)->isPreview($request) && app(HrAccess::class)->allows($request->user(), 'profile.view', $employee), 404);
        abort_unless($employee->user, 404);
        $profile = self::profileData($employee, $employee->user);
        if (! app(HrAccess::class)->allows($request->user(), 'profile.manage', $employee)) {
            unset($profile['emergency_contact']);
        }

        return Inertia::render('hr/Profile', ['profile' => $profile, 'readOnly' => true]);
    }
}
