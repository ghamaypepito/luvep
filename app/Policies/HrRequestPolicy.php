<?php

namespace App\Policies;

use App\Models\HrRequest;
use App\Models\User;
use App\Services\Hr\HrAccess;

class HrRequestPolicy
{
    public function eligibleAssignee(User $user): bool
    {
        $a = app(HrAccess::class);

        return $a->internal($user) && ($user->isAdmin() || $a->employee($user)) && ($a->allows($user, 'cases.manage') || $a->allows($user, 'cases.assigned'));
    }

    public function manage(User $actor, HrRequest $case): bool
    {
        $access = app(HrAccess::class);

        return $actor->id !== $case->employee->user_id && ($access->allows($actor, 'cases.manage') || ($case->assigned_user_id === $actor->id && $this->eligibleAssignee($actor)));
    }

    public function view(User $actor, HrRequest $case): bool
    {
        return $this->manage($actor, $case) || ($actor->id === $case->employee->user_id && app(HrAccess::class)->employee($actor)?->id === $case->hr_employee_id);
    }
}
