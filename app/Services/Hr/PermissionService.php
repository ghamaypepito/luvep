<?php

namespace App\Services\Hr;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PermissionService
{
    public function replace(User $actor, User $target, array $grants, int $version): void
    {
        abort_unless(app(HrAccess::class)->allows($actor, 'access.manage'), 403);
        Validator::make(compact('grants', 'version'), ['grants' => 'present|array', 'grants.*' => ['string', 'distinct', Rule::in(config('hr.abilities'))], 'version' => 'required|integer|min:0'])->validate();
        DB::transaction(function () use ($actor, $target, $grants, $version) {
            // Serialize grant changes with a stable lock order, including the last-admin check.
            $users = User::orderBy('id')->lockForUpdate()->get();
            $actor = $users->firstWhere('id', $actor->id);
            $target = $users->firstWhere('id', $target->id);
            $access = app(HrAccess::class);
            abort_unless($actor && $target && $access->allows($actor, 'access.manage'), 403);
            abort_if($target->isSuperAdmin(), 422, 'Super Admin access is defined by the account role.');
            if (! $access->internal($target) && $grants) {
                throw ValidationException::withMessages(['grants' => 'Only active internal accounts can receive HR permissions.']);
            }
            abort_unless((int) $target->hr_access_version === $version, 409, 'This account changed. Refresh before saving.');
            $old = collect((array) $target->module_permissions)->filter(fn ($g) => str_starts_with($g, 'hr.'))->map(fn ($g) => substr($g, 3))->values()->all();
            if ($actor->id === $target->id && array_diff($grants, $old)) {
                abort(403, 'An access administrator cannot add permissions to their own account.');
            }
            if ($access->allows($target, 'access.manage') && ! in_array('access.manage', $grants, true)) {
                abort_unless($users->contains(fn ($u) => $u->id !== $target->id && $access->allows($u, 'access.manage')), 409, 'At least one active access administrator must remain.');
            }
            $other = array_filter((array) $target->module_permissions, fn ($g) => $g !== 'hr' && ! str_starts_with($g, 'hr.'));
            sort($grants);
            $target->forceFill(['module_permissions' => array_values(array_unique([...$other, ...array_map(fn ($g) => 'hr.'.$g, $grants)])), 'hr_access_version' => $version + 1])->save();
            app(HrAudit::class)->record($actor, 'access.changed', $target, ['previous_grants' => $old, 'grants' => $grants, 'version' => $version + 1]);
        });
    }
}
