<?php

namespace App\Services\Hr;

use App\Models\DtrEntry;
use App\Models\HrDtrAcknowledgement;
use App\Models\User;
use App\Services\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DtrCalendar
{
    public function filters(Request $request): array
    {
        $today = now(ReportPeriod::TIMEZONE);

        return app(ReportService::class)->filters('attendance', [
            'from' => $today->copy()->startOfMonth()->toDateString(),
            'to' => $today->copy()->endOfMonth()->toDateString(),
            ...$request->only(['from', 'to', 'employee_id', 'department', 'status']),
        ]);
    }

    public function employees(Collection $employees, array $filters): Collection
    {
        if (isset($filters['employee_id'])) {
            abort_unless($employees->contains('id', (int) $filters['employee_id']), 404);
            $employees = $employees->where('id', (int) $filters['employee_id']);
        }
        if (isset($filters['department'])) {
            $employees = $employees->where('department', $filters['department']);
        }

        return $employees;
    }

    public function oldestPendingAcknowledgement(User $actor, Collection $employees): ?string
    {
        $date = DtrEntry::whereIn('user_id', $employees->pluck('user_id'))
            ->whereIn('id', HrDtrAcknowledgement::where('status', 'pending')
                ->when(! app(HrAccess::class)->allows($actor, 'dtr.review'), fn ($q) => $q->where('head_user_id', $actor->id))->select('dtr_entry_id'))
            ->min('work_date');

        return $date ? \Carbon\CarbonImmutable::parse($date)->toDateString() : null;
    }

    /** Callers supply their existing employee scope; filters may only narrow it. */
    public function records(User $actor, Collection $employees, array $filters): Collection
    {
        $filters = app(ReportService::class)->filters('attendance', $filters);
        $employees = $this->employees($employees, $filters);
        $employees = $employees->keyBy('user_id');
        $entries = DtrEntry::whereIn('user_id', $employees->keys())
            ->whereDate('work_date', '>=', $filters['from'])->whereDate('work_date', '<=', $filters['to'])
            ->when(isset($filters['status']), fn ($q) => $q->where('hr_status', $filters['status']))
            ->orderBy('work_date')->orderBy('id')->get(['id', 'user_id', 'work_date', 'hr_status', 'hr_version', 'time_source', 'time_in', 'time_out']);
        $totals = app(DtrService::class)->attendanceTotals($entries);
        $history = app(DtrHistory::class);
        $canView = $history->team($actor)->pluck('user_id');
        if (app(HrAccess::class)->employee($actor)) {
            $canView->push($actor->id);
        }
        $pending = HrDtrAcknowledgement::whereIn('dtr_entry_id', $entries->pluck('id'))->where('status', 'pending')
            ->when(! app(HrAccess::class)->allows($actor, 'dtr.review'), fn ($q) => $q->where(fn ($q) => $q->where('head_user_id', $actor->id)->orWhereHas('entry', fn ($q) => $q->where('user_id', $actor->id))))
            ->selectRaw('dtr_entry_id, COUNT(*) as total')->groupBy('dtr_entry_id')->pluck('total', 'dtr_entry_id');

        return $entries->map(fn ($entry) => [
            'id' => $entry->id, 'employee_id' => $employees[$entry->user_id]->id,
            'employee_name' => $employees[$entry->user_id]->user->name,
            'employee_number' => $employees[$entry->user_id]->employee_number,
            'department' => $employees[$entry->user_id]->department,
            'work_date' => $entry->work_date->toDateString(), 'hr_status' => $entry->hr_status,
            'hr_version' => $entry->hr_version, 'time_source' => $entry->time_source,
            ...$totals[$entry->id], 'pending_acknowledgements' => (int) ($pending[$entry->id] ?? 0),
            'can_view_details' => $canView->contains($entry->user_id),
        ]);
    }
}
