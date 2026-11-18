<?php

namespace App\Services\Hr;

use App\Models\HrEmployee;
use App\Models\HrMilestone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MilestoneService
{
    public function save(User $actor, HrEmployee $employee, array $data, ?HrMilestone $existing = null): HrMilestone
    {
        abort_unless(app(HrAccess::class)->allows($actor, 'operations.manage'), 403);
        $data = Validator::make($data, ['kind' => 'required|in:probation,contract,onboarding,exit,other', 'title' => 'required|string|max:200', 'due_date' => 'required|date_format:Y-m-d', 'owner_user_id' => 'required|integer', 'employee_visible' => 'required|boolean', 'private_note' => 'nullable|string|max:5000', 'version' => 'required|integer|min:0'])->validate();
        $owner = User::find($data['owner_user_id']);
        if (! $owner || ! app(ChecklistService::class)->eligibleOwner($owner)) {
            throw ValidationException::withMessages(['owner_user_id' => 'Choose an active employee or administrator.']);
        }

        return DB::transaction(function () use ($actor, $employee, $data, $existing) {
            $employee = HrEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            abort_if($employee->status === 'archived', 409, 'Archived employee records cannot be changed.');
            $m = $existing ? HrMilestone::whereKey($existing->id)->where('hr_employee_id', $employee->id)->lockForUpdate()->firstOrFail() : new HrMilestone(['hr_employee_id' => $employee->id]);
            abort_if($m->completed_at, 409, 'This milestone is complete.');
            abort_unless($m->version === (int) $data['version'], 409, 'The milestone changed. Refresh before saving.');
            if ($m->exists && $m->due_date->toDateString() !== $data['due_date']) {
                $m->deadline_revision++;
            }
            $m->fill([...$data, 'version' => $m->version + 1])->save();
            app(HrAudit::class)->record($actor, 'milestone.saved', $m, ['version' => $m->version]);

            return $m;
        });
    }

    public function visible(User $user, bool $manage = false)
    {
        $e = app(HrAccess::class)->employee($user);

        return HrMilestone::query()->when(! $manage, fn ($q) => $q->where(fn ($q) => $q->where('owner_user_id', $user->id)->orWhere(fn ($q) => $q->where('hr_employee_id', $e?->id ?? 0)->where('employee_visible', true))));
    }

    public function canComplete(User $actor, HrMilestone $m): bool
    {
        return $m->employee->status !== 'archived' && (app(HrAccess::class)->allows($actor, 'operations.manage') || ($m->owner_user_id === $actor->id && app(ChecklistService::class)->eligibleOwner($actor)));
    }

    public function complete(User $actor, HrMilestone $m, int $version): HrMilestone
    {
        return DB::transaction(function () use ($actor, $m, $version) {
            $m = HrMilestone::whereKey($m->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canComplete($actor, $m), 403);
            if ($m->completed_at) {
                return $m;
            }abort_unless($m->version === $version, 409, 'Refresh this milestone before completing it.');
            $m->update(['completed_at' => now(), 'completed_by' => $actor->id, 'version' => $version + 1]);
            app(HrAudit::class)->record($actor,'milestone.completed',$m);

            return $m;
        });
    }
}
