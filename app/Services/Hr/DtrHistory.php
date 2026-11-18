<?php

namespace App\Services\Hr;

use App\Models\DtrEntry;
use App\Models\HrDtrRevision;
use App\Models\HrEmployee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DtrHistory
{
    // Report values stay in the scoped DTR history, not the broader HR activity feed.
    public function snapshot(DtrEntry $entry): array
    {
        return [...$entry->only(['tasks', 'work_location', 'blockers', 'tomorrow', 'remarks', 'hr_status', 'declared_name']),
            'meetings' => (int) $entry->meetings, 'people_assisted' => (int) $entry->people_assisted,
            'submitted_at' => $entry->submitted_at?->toISOString()];
    }

    public function record(DtrEntry $entry, User $actor, string $action, array $before): void
    {
        $changes = [];
        foreach ($this->snapshot($entry) as $field => $after) {
            if (($before[$field] ?? null) !== $after) {
                $changes[$field] = ['before' => $before[$field] ?? null, 'after' => $after];
            }
        }
        HrDtrRevision::create(['dtr_entry_id' => $entry->id, 'actor_id' => $actor->id,
            'version' => $entry->hr_version, 'action' => $action, 'changes' => $changes, 'created_at' => now()]);
    }

    /** Team scope excludes self; employees read their own history from My time records. */
    public function team(User $actor): Builder
    {
        $access = app(HrAccess::class);
        $scope = HrEmployee::where('user_id', '!=', $actor->id);
        if (! $access->internal($actor)) {
            return $scope->whereRaw('1 = 0');
        }
        if ($access->allows($actor, 'dtr.review')) {
            return $scope;
        }
        if (! $access->employee($actor) && ! $actor->isAdmin()) {
            return $scope->whereRaw('1 = 0');
        }

        return $scope->where(fn ($q) => $q->where('manager_user_id', $actor->id)->orWhere('reviewer_user_id', $actor->id));
    }

    public function canView(User $actor, DtrEntry $entry): bool
    {
        return ($entry->user_id === $actor->id && app(HrAccess::class)->employee($actor) !== null)
            || $this->team($actor)->where('user_id', $entry->user_id)->exists();
    }
}
