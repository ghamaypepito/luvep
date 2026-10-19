<?php

namespace App\Services\Hr;

use App\Models\HrEmployee;
use App\Models\User;
use Illuminate\Http\Request;

class HrContext
{
    public function actor(Request $request): User
    {
        return $request->user();
    }

    public function isPreview(Request $request): bool
    {
        return $request->session()->has('hr_preview_employee_id');
    }

    public function validate(Request $request): void
    {
        if (! $this->isPreview($request)) {
            return;
        }
        $actor = $request->user();
        $employee = HrEmployee::find($request->session()->get('hr_preview_employee_id'));
        $access = app(HrAccess::class);
        if (! $actor || ! $access->allows($actor, 'preview', $employee) || ! $employee?->user || $access->employee($employee->user)?->id !== $employee->id) {
            $request->session()->forget('hr_preview_employee_id');
            if ($actor && $employee) {
                app(HrAudit::class)->record($actor, 'preview.revoked', $employee);
            }
            abort(403, 'Employee preview is no longer available.');
        }
    }

    public function subject(Request $request): HrEmployee
    {
        $this->validate($request);
        $employee = $this->isPreview($request)
            ? HrEmployee::find($request->session()->get('hr_preview_employee_id'))
            : app(HrAccess::class)->employee($this->actor($request));
        abort_unless($employee, 403, 'An active employee profile is required.');

        return $employee;
    }

    public function url(string $path = ''): string
    {
        return rtrim(config('hr.url'), '/').'/'.ltrim($path, '/');
    }

    public function props(Request $request): array
    {
        $this->validate($request);
        $actor = $request->user();
        $preview = $this->isPreview($request);
        $access = app(HrAccess::class);
        $employee = $preview ? $this->subject($request) : ($actor ? $access->employee($actor) : null);
        $abilities = [];
        foreach (config('hr.abilities') as $ability) {
            $abilities[$ability] = ! $preview && $actor && $access->allows($actor, $ability);
        }

        if (! $preview && $actor && $access->internal($actor)) {
            $abilities['dtr.review'] = $abilities['dtr.review'] || HrEmployee::where('reviewer_user_id', $actor->id)->where('user_id', '!=', $actor->id)->exists();
        }

        return ['enabled' => (bool) config('hr.enabled'), 'url' => $this->url(), 'employee' => $employee ? ['id' => $employee->id, 'name' => $employee->user->name] : null,
            'permissions' => $abilities, 'preview' => $preview ? ['employee_id' => $employee->id, 'employee_name' => $employee->user->name, 'read_only' => true] : null];
    }
}
