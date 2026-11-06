<?php

namespace App\EpAgent;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ManagerService
{
    private const RECORD_FIELDS = ['id', 'first_name', 'last_name', 'email', 'phone', 'education_stage', 'immigration_stage', 'updated_at', 'assigned_to'];

    public function __construct(private Access $access, private Pilot $pilot) {}

    private function scope(User $user, string $portal)
    {
        $this->pilot->requireManager($user, $portal);

        return $this->access->leads($user, $portal);
    }

    public function overview(User $user, string $portal, ?int $before = null, bool $attention = false): array
    {
        $query = $this->scope($user, $portal);
        $cutoff = now()->subDays(7);
        $counts = ['total' => (clone $query)->count(), 'inactive' => (clone $query)->whereNotNull('updated_at')->where('updated_at', '<', $cutoff)->count(),
            'unknown_update' => (clone $query)->whereNull('updated_at')->count(), 'unassigned' => (clone $query)->whereNull('assigned_to')->count()];
        $list = clone $query;
        if ($before) {
            $list->where('id', '<', $before);
        }
        if ($attention) {
            $list->whereNotNull('updated_at')->where('updated_at', '<', $cutoff);
        }
        $rows = $list->orderByDesc('id')->limit(26)->get(self::RECORD_FIELDS);
        $more = $rows->count() > 25;
        $rows = $rows->take(25);
        $staff = User::whereIn('id', $rows->pluck('assigned_to')->filter()->unique())->where('is_active', true)->get(['id', 'name', 'role'])->keyBy('id');
        $team = (clone $query)->whereNotNull('assigned_to')->select('assigned_to')->selectRaw('COUNT(*) as workload')->groupBy('assigned_to');
        $teamRows = DB::query()->fromSub($team->toBase(), 'work')->join('users', 'users.id', '=', 'work.assigned_to')
            ->whereNull('users.deleted_at')->where('users.is_active', true)->orderBy('users.id')->limit(25)->get(['users.id', 'users.name', 'users.role', 'work.workload']);

        return ['portal' => $portal, 'as_of' => now()->toIso8601String(), 'counts' => $counts, 'records' => $rows->map(fn ($lead) => $this->record($lead, $portal, $staff->get($lead->assigned_to)))->values(),
            'next_before' => $more ? $rows->last()->id : null, 'team' => $teamRows, 'team_limited' => $teamRows->count() === 25, 'attention_days' => 7];
    }

    private function record($lead, string $portal, ?User $personnel): array
    {
        return ['id' => $lead->id, 'client' => ['id' => $lead->id, 'name' => trim($lead->first_name.' '.$lead->last_name)],
            'personnel' => $personnel ? ['id' => $personnel->id, 'name' => $personnel->name, 'role' => $personnel->role] : null,
            'assignment_status' => $personnel ? 'assigned' : ($lead->assigned_to ? 'unavailable' : 'unassigned'),
            'portal' => $portal, 'stage' => $portal === 'education' ? $lead->education_stage : $lead->immigration_stage,
            'updated_at' => $lead->updated_at?->toIso8601String()];
    }

    public function client(User $user, string $portal, int $id): array
    {
        $lead = $this->scope($user, $portal)->whereKey($id)->firstOrFail(self::RECORD_FIELDS);
        $personnel = User::where('is_active', true)->find($lead->assigned_to, ['id', 'name', 'role']);
        $record = $this->record($lead, $portal, $personnel);

        return array_merge(['id' => $lead->id, 'name' => $record['client']['name'], 'email' => $lead->email, 'phone' => $lead->phone], array_diff_key($record, ['client' => true]));
    }

    public function personnel(User $user, string $portal, int $id): array
    {
        $query = $this->scope($user, $portal)->where('assigned_to', $id);
        abort_unless((clone $query)->exists(), 404, 'ep_not_found');
        $staff = User::where('is_active', true)->findOrFail($id, ['id', 'name', 'email', 'role']);

        return ['id' => $staff->id, 'name' => $staff->name, 'work_email' => $staff->email, 'role' => $staff->role, 'workload' => (clone $query)->count(), 'portal' => $portal];
    }
}
