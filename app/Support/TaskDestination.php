<?php

namespace App\Support;

use App\Models\User;

/** Assignment links belong to the recipient's portal, not the creator's. */
class TaskDestination
{
    public static function forUser(User $user, ?int $taskId = null): string
    {
        $board = match ($user->role) {
            'admin', 'super_admin' => '/admin/tasks',
            'sales' => '/portal/sales/tasks',
            'education' => '/portal/education/tasks',
            'immigration', 'immigration_manager' => '/portal/immigration/tasks',
            'immigration_adviser' => '/portal/immigration-adviser/tasks',
            'accommodation' => '/portal/accommodation/tasks',
            'finance' => '/portal/finance/tasks',
            default => null,
        };

        // Roles without a task board retain the safe dashboard fallback.
        if (! $board) {
            return $user->homeRoute();
        }

        return $board.($taskId && $taskId > 0 ? '?task='.$taskId : '');
    }
}
