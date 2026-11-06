<?php

namespace App\EpAgent;

use App\Models\User;

class Pilot
{
    public function state(?User $user): array
    {
        $off = ['enabled' => false, 'manager' => false, 'portals' => []];
        if (! config('ep_agent.enabled') || ! config('ep_agent.ui_enabled') || ! $user || request()->attributes->has('user_view_actor')) {
            return $off;
        }
        try {
            $user = User::find($user->id);
            if (! $user || ! $user->is_active || ! in_array($user->role, Access::STAFF_ROLES, true)) {
                return $off;
            }
            if (! in_array($user->id, config('ep_agent.pilot_user_ids', []), true) && ! (config('ep_agent.pilot_admins') && $user->isAdmin())) {
                return $off;
            }
            $portals = array_values(array_filter(array_intersect(['education', 'immigration'], config('ep_agent.portals', [])), fn ($p) => $user->canAccessPortal($p)));
            if (! $portals) {
                return $off;
            }

            return ['enabled' => true, 'manager' => $user->isAdmin() || $user->role === 'immigration_manager', 'portals' => $portals];
        } catch (\Throwable) {
            // Pilot failures must not break navigation for the rest of Luvep.
            return $off;
        }
    }

    public function requireUser(User $user): array
    {
        $state = $this->state($user);
        abort_unless($state['enabled'], 403, 'ep_pilot_unavailable');

        return $state;
    }

    public function requireManager(User $user, ?string $portal = null): array
    {
        $state = $this->requireUser($user);
        abort_unless($state['manager'], 403, 'ep_access_denied');
        $fresh = User::findOrFail($user->id);
        if (! $fresh->isAdmin()) {
            $state['portals'] = array_values(array_intersect($state['portals'], ['immigration']));
        }
        if ($portal !== null) {
            abort_unless(in_array($portal, $state['portals'], true), 403, 'ep_access_denied');
        }

        return $state;
    }
}
