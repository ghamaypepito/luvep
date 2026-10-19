<?php

namespace App\Services\Hr;

use App\Jobs\Hr\BuildHrExport;
use App\Models\DtrEntry;
use App\Models\DtrLeave;
use App\Models\HrEmployee;
use App\Models\HrExport;
use App\Models\HrPolicyAcknowledgement;
use App\Models\HrPolicyVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportService
{
    public const TYPES = ['employee_status', 'attendance', 'leave', 'policy_acknowledgements', 'approved_hours'];

    public function filters(string $type, array $filters): array
    {
        $data = Validator::make(['type' => $type, ...$filters], ['type' => ['required', Rule::in(self::TYPES)], 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'employee_id' => 'nullable|integer|min:1', 'department' => 'nullable|string|max:120', 'status' => 'nullable|string|max:30'])->validate();
        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 365) {
            throw ValidationException::withMessages(['to' => 'Choose a range of at most 366 days.']);
        }
        $statuses = match ($type) {
            'employee_status' => ['candidate', 'active', 'archived'],'attendance' => ['legacy', 'draft', 'submitted', 'reviewed', 'returned'],'approved_hours' => ['reviewed'],'leave' => ['pending', 'approved', 'rejected', 'deferred', 'cancelled'],'policy_acknowledgements' => []
        };
        if (! empty($data['status']) && ! in_array($data['status'], $statuses, true)) {
            throw ValidationException::withMessages(['status' => 'Choose a valid status for this report.']);
        }
        unset($data['type']);

        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }

    public function employees(User $actor, string $type)
    {
        $access = app(HrAccess::class);
        abort_unless($access->internal($actor), 403);
        abort_unless(in_array($type, self::TYPES, true), 422);
        $own = $access->employee($actor);
        $global = $access->allows($actor, 'reports');
        $allDtr = in_array($type, ['attendance', 'approved_hours'], true) && $access->allows($actor, 'dtr.review');
        $allLeave = $type === 'leave' && ($access->allows($actor, 'leave.review') || $access->allows($actor, 'leave.fallback') || $access->allows($actor, 'leave.emergency'));

        return HrEmployee::with(['user', 'schedule'])->whereHas('user')->when(! $global && ! $allDtr && ! $allLeave, function ($q) use ($actor, $type, $own) {
            $q->where(function ($q) use ($actor, $type, $own) {
                $q->where('id', $own?->id ?? 0);
                if ($own || $actor->isAdmin()) {
                    if (in_array($type, ['attendance', 'approved_hours'], true)) {
                        $q->orWhere('reviewer_user_id', $actor->id);
                    }if ($type === 'leave') {
                        $q->orWhere('manager_user_id', $actor->id);
                    }
                }
            });
        })->orderBy('id')->get();
    }

    public function scopeHash(User $actor, string $type, array $filters): string
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        $scope = $this->employees($actor, $type);
        if (isset($filters['employee_id'])) {
            abort_unless($scope->contains('id', (int) $filters['employee_id']), 404);
        }

        return hash('sha256', json_encode([$actor->id, $actor->role, $actor->is_active, $actor->module_permissions, $scope->map(fn ($e) => [$e->id, $e->user_id, $e->status, $e->department, $e->manager_user_id, $e->reviewer_user_id, $e->user?->is_active, $e->user?->role, $e->schedule?->archived_at]), $type, $filters]));
    }

    public function rows(User $actor, string $type, array $filters): LazyCollection
    {
        $filters = $this->filters($type, $filters);
        $employees = $this->employees($actor, $type);
        if (isset($filters['employee_id'])) {
            abort_unless($employees->contains('id', (int) $filters['employee_id']), 404);
            $employees = $employees->where('id', (int) $filters['employee_id']);
        }if (isset($filters['department'])) {
            $employees = $employees->where('department', $filters['department']);
        }

        return LazyCollection::make(function () use ($employees, $type, $filters) {
            $byUser = $employees->keyBy('user_id');
            $ids = $byUser->keys();
            $base = fn ($e) => ['employee' => $e->user->name, 'employee_number' => $e->employee_number ?? '', 'department' => $e->department ?? ''];
            if ($type === 'employee_status') {
                foreach ($employees as $e) {
                    if (isset($filters['status']) && $e->status !== $filters['status']) {
                        continue;
                    }yield [...$base($e), 'status' => $e->status, 'position' => $e->position ?? '', 'employment_start' => $e->employment_start?->toDateString() ?? '', 'employment_end' => $e->employment_end?->toDateString() ?? ''];
                }

return;
            }
            if (in_array($type, ['attendance', 'approved_hours'], true)) {
                $q = DtrEntry::whereIn('user_id', $ids)->whereDate('work_date', '>=', $filters['from'])->whereDate('work_date', '<=', $filters['to']);
                if ($type === 'approved_hours') {
                    $q->where('hr_status', 'reviewed');
                }if (isset($filters['status'])) {
                    $q->where('hr_status', $filters['status']);
                }foreach ($q->orderBy('id')->lazyById(100) as $entry) {
                    $row = app(DtrService::class)->row($entry);
                    yield [...$base($byUser[$entry->user_id]), 'work_date' => $row['work_date'], 'status' => $entry->hr_status, 'time_source' => $entry->time_source, 'working_seconds' => $row['working_seconds'], 'break_seconds' => $row['break_seconds']];
                }

return;
            }
            if ($type === 'leave') {
                $q = DtrLeave::whereIn('user_id', $ids)->whereDate('end_date', '>=', $filters['from'])->whereDate('start_date', '<=', $filters['to']);
                if (isset($filters['status'])) {
                    $q->where('status', $filters['status']);
                }foreach ($q->lazyById(100) as $l) {
                    yield [...$base($byUser[$l->user_id]), 'start_date' => $l->start_date->toDateString(), 'end_date' => $l->end_date->toDateString(), 'working_days' => $l->total_days, 'status' => $l->status];
                }

return;
            }
            foreach (HrPolicyAcknowledgement::whereIn('user_id', $ids)->whereDate('acknowledged_at', '>=', $filters['from'])->whereDate('acknowledged_at', '<=', $filters['to'])->lazyById(100) as $ack) {
                $v = HrPolicyVersion::find($ack->hr_policy_version_id);
                yield [...$base($byUser[$ack->user_id]), 'policy' => $v?->title ?? '', 'version' => $v?->version ?? '', 'acknowledged_at' => $ack->acknowledged_at->toIso8601String()];
            }
        });
    }

    public function requestExport(User $actor, string $type, array $filters): HrExport
    {
        $filters = $this->filters($type, $filters);
        $hash = $this->scopeHash($actor, $type, $filters);

        return DB::transaction(function () use ($actor, $type, $filters, $hash) {
            $x = HrExport::create(['user_id' => $actor->id, 'type' => $type, 'filters' => $filters, 'scope_hash' => $hash, 'status' => 'queued', 'expires_at' => now()->addDay()]);
            app(HrAudit::class)->record($actor, 'report.export_requested', $x);
            BuildHrExport::dispatch($x->id)->afterCommit();

            return $x;
        });
    }

    public function authorized(HrExport $export): bool
    {
        if ($export->expires_at->isPast() || ! $export->requester) {
            return false;
        }
        try {
            return hash_equals($export->scope_hash, $this->scopeHash($export->requester, $export->type, $export->filters));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            return false;
        }
    }

    public function csvCell(mixed $value): string
    {
        $s = (string) ($value ?? '');

        return preg_match('/^[\s\x00-\x1f]*[=+@-]|^[\t\r\n]/u',$s) ? "'".$s : $s;
    }
}
