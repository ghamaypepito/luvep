<?php

namespace App\Services\Hr;

use App\Models\DtrEntry;
use App\Models\HrEmployee;
use App\Models\HrShift;
use App\Models\User;

class HrAccess
{
    /** Draft enrollment alone must not take over an existing legacy DTR. */
    public function requiresHrDtr(int $userId): bool
    {
        $employee = HrEmployee::with('user')->where('user_id', $userId)->first();
        if ($employee && ($employee->status !== 'candidate' || ! $employee->user || ! $this->internal($employee->user)
            || HrShift::where('hr_employee_id', $employee->id)->exists())) {
            return true;
        }

        return DtrEntry::where('user_id', $userId)->where('time_source', 'live')->exists();
    }

    public function internal(User $user): bool
    {
        if (! $user->is_active || $user->trashed() || ! in_array($user->role, config('hr.internal_roles'), true)) {
            return false;
        }
        $enrollment = HrEmployee::where('user_id', $user->id)->first();

        return ! $enrollment || ($enrollment->status !== 'archived' && ! $enrollment->schedule?->archived_at);
    }

    public function employee(User $user): ?HrEmployee
    {
        if (! $this->internal($user)) {
            return null;
        }

        return HrEmployee::where('user_id', $user->id)->where('status', 'active')
            ->whereDoesntHave('schedule', fn ($q) => $q->whereNotNull('archived_at'))->first();
    }

    public function allows(User $actor, string $ability, ?HrEmployee $subject = null): bool
    {
        if (! $this->internal($actor) || ! in_array($ability, config('hr.abilities'), true)) {
            return false;
        }

        return $this->allowsInternal($actor, $ability, $subject);
    }

    /** Evaluate the subject-free menu as one snapshot, without caching access decisions. */
    public function menuPermissions(User $actor): array
    {
        $internal = $this->internal($actor);
        $permissions = [];
        foreach (config('hr.abilities') as $ability) {
            $permissions[$ability] = $internal && $this->allowsInternal($actor, $ability);
        }

        return $permissions;
    }

    /** Navigation may reuse its fresh subject-free permission and employee snapshot. */
    public function canViewStaffStatus(User $actor, ?array $permissions = null, ?bool $hasEmployee = null): bool
    {
        $permissions ??= $this->menuPermissions($actor);
        if ($permissions['dtr.review'] || $permissions['reports']) {
            return true;
        }

        return ($hasEmployee ?? $this->employee($actor) !== null) && HrEmployee::where('status', 'active')
            ->where('user_id', '!=', $actor->id)
            ->where(fn ($q) => $q->where('manager_user_id', $actor->id)->orWhere('reviewer_user_id', $actor->id))->exists();
    }

    private function allowsInternal(User $actor, string $ability, ?HrEmployee $subject = null): bool
    {
        if (in_array($ability, ['leave.review', 'leave.fallback', 'leave.emergency', 'dtr.review'], true) && $subject?->user_id === $actor->id) {
            return false;
        }
        if ($subject && $ability === 'profile.view' && $subject->user_id === $actor->id) {
            return $this->employee($actor)?->id === $subject->id;
        }
        if ($ability === 'leave.review' && $subject?->manager_user_id === $actor->id) {
            return $this->employee($actor) !== null || $actor->isAdmin();
        }
        if ($ability === 'dtr.review' && $subject?->reviewer_user_id === $actor->id) {
            return $this->employee($actor) !== null || $actor->isAdmin();
        }
        if ($ability === 'dtr.review' && $actor->isAdmin()) {
            return true;
        }
        if ($actor->isSuperAdmin()) {
            return true;
        }

        // Deliberately do not use canSeeModule: a parent HR grant must not expose sensitive classes.
        return in_array('hr.'.$ability, (array) $actor->module_permissions, true)
            || ($actor->role === User::ROLE_ADMIN && in_array($ability, config('hr.admin_defaults'), true));
    }
}
