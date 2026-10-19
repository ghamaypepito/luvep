<?php

namespace App\Services\Hr;

use App\Models\HrEmployee;
use App\Models\User;

class HrAccess
{
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
