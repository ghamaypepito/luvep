<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

class UserView
{
    public static function props(Request $request): array
    {
        $actor = $request->attributes->get('user_view_actor') ?? $request->user();
        $available = (bool) ($actor?->isSuperAdmin() && $actor->is_active);
        $target = $available ? $request->attributes->get('user_view_target') : null;

        return [
            'available' => $available, 'active' => (bool) $target,
            'actor' => $available ? $actor->only(['id', 'name']) : null,
            'target' => $target?->only(['id', 'name', 'role']),
            'token' => $target ? $request->session()->get('user_view.token') : 'none',
        ];
    }

    public static function audit(Request $request, User $actor, ?User $target, string $action, array $metadata = []): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $actor->id, 'actor_name' => $actor->name, 'actor_role' => $actor->role,
            'portal' => 'admin', 'action' => $action, 'ip_address' => $request->ip(),
            'description' => $target ? $actor->name.' acting as '.$target->name : $actor->name.' ended user view',
            'metadata' => array_merge($metadata, ['user_view' => [
                'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'target_id' => $target?->id, 'target_name' => $target?->name, 'target_role' => $target?->role,
            ]]),
        ]);
    }
}
