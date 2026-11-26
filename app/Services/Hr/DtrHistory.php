<?php

namespace App\Services\Hr;

use App\Models\DtrEntry;
use App\Models\HrDtrAcknowledgement;
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

    public function reportSnapshot(DtrEntry $entry): array
    {
        return array_intersect_key($this->snapshot($entry), array_flip(['tasks', 'work_location', 'meetings', 'people_assisted', 'blockers', 'tomorrow', 'remarks']));
    }

    public function reportBaseline(DtrEntry $entry): array
    {
        $acknowledged = HrDtrAcknowledgement::where('dtr_entry_id', $entry->id)->where('status', 'acknowledged')->latest('id')->first();
        if ($acknowledged) {
            return $acknowledged->snapshot;
        }
        $baseline = $this->reportSnapshot($entry);
        $firstEdit = HrDtrRevision::where('dtr_entry_id', $entry->id)->where('action', 'dtr.report_saved')->min('version');
        if ($firstEdit !== null) {
            // Rewind subsequent content changes to the report before its first immediate edit.
            foreach (HrDtrRevision::where('dtr_entry_id', $entry->id)->where('version', '>=', $firstEdit)->orderByDesc('version')->get() as $revision) {
                foreach ($revision->changes as $field => $change) {
                    if (array_key_exists($field, $baseline)) {
                        $baseline[$field] = $change['before'];
                    }
                }
            }
        }

        return $baseline;
    }

    public function reportChanges(DtrEntry $entry): array
    {
        $baseline = $this->reportBaseline($entry);
        $changes = [];
        foreach ($this->reportSnapshot($entry) as $field => $after) {
            if (($baseline[$field] ?? null) !== $after) {
                $changes[$field] = ['before' => $baseline[$field] ?? null, 'after' => $after];
            }
        }

        return $changes;
    }

    public function departmentHead(HrEmployee $employee): ?User
    {
        $head = $employee->manager;
        $access = app(HrAccess::class);

        return $head && $head->id !== $employee->user_id && $access->internal($head) && ($access->employee($head) || $head->isAdmin()) ? $head : null;
    }

    public function canDecide(User $actor, HrDtrAcknowledgement $acknowledgement, ?HrEmployee $employee = null): bool
    {
        $employee ??= HrEmployee::where('user_id', $acknowledgement->entry->user_id)->first();

        return $employee && $this->departmentHead($employee)?->id === $actor->id && $acknowledgement->head_user_id === $actor->id;
    }

    public function acknowledgementRow(HrDtrAcknowledgement $acknowledgement, User $actor): array
    {
        return [...$acknowledgement->only(['id', 'dtr_entry_id', 'report_version', 'version', 'status', 'reason', 'snapshot', 'changes', 'submitted_at', 'decided_at', 'comment']),
            'head_name' => $acknowledgement->head?->name ?? 'Former team member', 'employee_name' => $acknowledgement->entry?->user?->name ?? 'Former team member',
            'work_date' => $acknowledgement->entry?->work_date->toDateString(),
            'can_decide' => $acknowledgement->status === 'pending' && $this->reportSnapshot($acknowledgement->entry) === $acknowledgement->snapshot && $this->canDecide($actor, $acknowledgement)];
    }

    /** Super Admin can read every employee; decision policies still forbid self-review. */
    public function team(User $actor): Builder
    {
        $access = app(HrAccess::class);
        $scope = HrEmployee::with('user')->whereHas('user')->when(! $actor->isSuperAdmin(), fn ($q) => $q->where('user_id', '!=', $actor->id));
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
