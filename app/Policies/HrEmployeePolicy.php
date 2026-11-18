<?php

namespace App\Policies;

use App\Models\HrEmployee;
use App\Models\User;
use App\Services\Hr\HrAccess;
use Illuminate\Auth\Access\Response;

class HrEmployeePolicy
{
    public function view(User $actor, HrEmployee $employee): Response
    {
        return app(HrAccess::class)->allows($actor, 'profile.view', $employee) ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $actor, HrEmployee $employee): bool
    {
        return app(HrAccess::class)->allows($actor, 'profile.manage', $employee);
    }
}
