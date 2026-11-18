<?php

namespace App\Services\Reports;

use App\Models\LeadTask;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ReportWeek
{
    public function __construct(
        public readonly string $preset,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    public static function forPreset(mixed $preset, ?CarbonImmutable $now = null): ?self
    {
        $today = ($now ?? CarbonImmutable::now(ReportPeriod::TIMEZONE))->startOfDay();
        $monday = $today->startOfWeek(CarbonImmutable::MONDAY);

        return match ($preset) {
            'this_week' => new self($preset, $monday, $today->addDay()),
            'last_week' => new self($preset, $monday->subWeek(), $monday),
            default => null,
        };
    }

    public static function canUseNotes(?User $user): bool
    {
        return $user?->is_active && in_array($user->role, config('hr.internal_roles'), true);
    }

    public function dates(): array
    {
        return [$this->start->toDateString(), $this->end->subDay()->toDateString()];
    }

    public function notes(string $scope): array
    {
        [$from, $to] = $this->dates();

        return [
            'from' => $from,
            'to' => $to,
            'note' => (string) Setting::get(self::key($scope, $from), ''),
            'personal' => str_contains($scope, ':'),
            ...($scope === 'education' ? ['entries' => EducationWeeklyNotes::entries($from)] : []),
        ];
    }

    public function tasks(?string $department, ?int $assigneeId = null): array
    {
        $withinWeek = fn ($query, $column) => $query
            ->where($column, '>=', $this->start->utc())
            ->where($column, '<', $this->end->utc());
        $tasks = LeadTask::query()->when($department, fn ($query) => $query->where('department', $department))
            ->when($assigneeId, fn ($query) => $query->assignedTo($assigneeId));
        $completed = $withinWeek((clone $tasks)->where('completed', true), 'completed_at');

        return [
            'created' => $withinWeek($tasks, 'created_at')->count(),
            'completed' => (clone $completed)->count(),
            'recent' => $completed->orderByDesc('completed_at')->orderByDesc('id')->limit(6)
                ->get(['id', 'title', 'completed_at'])
                ->map(fn (LeadTask $task) => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'date' => $task->completed_at->setTimezone(ReportPeriod::TIMEZONE)->toDateString(),
                ])->all(),
        ];
    }

    public static function save(Request $request, string $scope): RedirectResponse
    {
        abort_unless(self::canUseNotes($request->user()), 403);
        $data = $request->validate([
            'week_start' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now(ReportPeriod::TIMEZONE)->toDateString()],
            'note' => ['present', 'nullable', 'string', 'max:4000'],
        ]);
        if (! CarbonImmutable::parse($data['week_start'], ReportPeriod::TIMEZONE)->isMonday()) {
            throw ValidationException::withMessages(['week_start' => 'Select a report week starting on Monday.']);
        }

        $key = self::key($scope, $data['week_start']);
        Setting::upsert([[
            'key' => $key, 'value' => $data['note'] ?? '', 'type' => 'string',
            'label' => 'Weekly report note', 'group' => 'reports',
        ]], ['key'], ['value', 'updated_at']);
        Cache::store('array')->forget("setting:{$key}");

        return back()->with('success', 'Weekly report note saved.');
    }

    private static function key(string $scope, string $monday): string
    {
        return "weekly_report_note:{$scope}:{$monday}";
    }
}
