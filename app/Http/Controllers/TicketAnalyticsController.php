<?php

namespace App\Http\Controllers;

use App\Models\SystemTicket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class TicketAnalyticsController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()->is_active && $request->user()->isAdmin(), 403);
        $filters = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'department' => 'nullable|string|max:80', 'priority' => 'nullable|in:low,normal,high,urgent', 'status' => 'nullable|in:open,in_review,planned,in_progress,done,declined', 'assigned_to' => 'nullable|integer']);
        $query = SystemTicket::query()->with(['assignee:id,name', 'messages', 'events']);
        foreach (['department', 'priority', 'status', 'assigned_to'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'], 'Asia/Manila')->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', CarbonImmutable::parse($filters['to'], 'Asia/Manila')->endOfDay()->utc());
        }
        $tickets = $query->orderByDesc('created_at')->get();
        $now = CarbonImmutable::now();
        $hours = fn ($a, $b) => max(0, CarbonImmutable::parse($a)->diffInSeconds(CarbonImmutable::parse($b)) / 3600);
        $responses = [];
        $assignments = [];
        $resolutions = [];
        $overdue = 0;
        $targets = 0;
        $reopened = 0;
        $timings = [];
        foreach ($tickets as $t) {
            $reply = $t->messages->filter(fn ($m) => $m->is_support && $m->user_id !== $t->submitted_by)->sortBy('created_at')->first();
            // Legacy first responses may predate tracking: exclude them from latency denominators.
            $completeHistory = $t->tracking_started_at && $t->tracking_started_at->lte($t->created_at->copy()->addSeconds(2));
            if ($reply && $completeHistory) {
                $responses[] = $hours($t->created_at, $reply->created_at);
            }
            if ($t->assigned_at && $completeHistory) {
                $assignments[] = $hours($t->created_at, $t->assigned_at);
            }
            if ($t->status === 'done' && $t->resolved_at) {
                $resolutions[] = $hours($t->created_at, $t->resolved_at);
            }
            if ($t->response_target_hours && $completeHistory) {
                $targets++;
                if (($reply ? $hours($t->created_at, $reply->created_at) : $hours($t->created_at, $now)) > $t->response_target_hours) {
                    $overdue++;
                }
            }
            $events = $t->events->sortBy('created_at')->values();
            $reopened += $events->filter(fn ($e) => $e->kind === 'status_changed' && in_array($e->from_status, ['done', 'declined']) && ! in_array($e->to_status, ['done', 'declined']))->count();
            $statusHours = [];
            $waitingHours = [];
            if ($t->tracking_started_at) {
                $changes = $events->where('kind', 'status_changed')->values();
                $state = $changes->first()?->from_status ?? $t->status;
                $cursor = $t->tracking_started_at;
                $intervals = [];
                foreach ($changes as $e) {
                    if (! in_array($state, ['done', 'declined'])) {
                        $statusHours[$state] = ($statusHours[$state] ?? 0) + $hours($cursor, $e->created_at);
                        $intervals[] = [$cursor, $e->created_at];
                    }
                    $state = $e->to_status;
                    $cursor = $e->created_at;
                }
                if (! in_array($state, ['done', 'declined'])) {
                    $statusHours[$state] = ($statusHours[$state] ?? 0) + $hours($cursor, $now);
                    $intervals[] = [$cursor, $now];
                }
                $addWaiting = function ($state, $from, $to) use (&$waitingHours, $intervals, $hours) {
                    if (! $state) {
                        return;
                    }
                    foreach ($intervals as [$start,$end]) {
                        $left = max(CarbonImmutable::parse($from), CarbonImmutable::parse($start));
                        $right = min(CarbonImmutable::parse($to), CarbonImmutable::parse($end));
                        if ($right > $left) {
                            $waitingHours[$state] = ($waitingHours[$state] ?? 0) + $hours($left, $right);
                        }
                    }
                };
                $waiting = $events->where('kind', 'waiting_changed')->values();
                $state = $waiting->first()?->metadata['from'] ?? ($completeHistory ? 'support' : null);
                $cursor = $t->tracking_started_at;
                foreach ($waiting as $e) {
                    $addWaiting($state, $cursor, $e->created_at);
                    $state = $e->metadata['to'] ?? null;
                    $cursor = $e->created_at;
                }
                $addWaiting($state, $cursor, $now);

            }
            $timings[] = ['ticket_ref' => $t->ticket_ref, 'title' => $t->title, 'age_hours' => round($hours($t->created_at, $t->resolved_at ?? $now), 1), 'status' => $t->status, 'tracking_started_at' => $t->tracking_started_at?->toIso8601String(), 'status_hours' => array_map(fn ($n) => round($n, 1), $statusHours), 'waiting_hours' => array_map(fn ($n) => round($n, 1), $waitingHours)];
        }
        $avg = fn ($values) => count($values) ? round(array_sum($values) / count($values), 1) : null;
        $median = function ($v) {
            if (! $v) {
                return null;
            }sort($v);
            $n = count($v);

            return round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2, 1);
        };
        $open = $tickets->whereNotIn('status', ['done', 'declined']);
        $done = $tickets->where('status', 'done');
        $groups = [];
        foreach (['status', 'priority', 'category', 'department', 'topic'] as $key) {
            $groups[$key] = $tickets->groupBy(fn ($t) => $t->$key ?: 'Unclassified')->map(fn ($rows, $label) => ['label' => $label, 'count' => $rows->count()])->values();
        }
        $groups['aging'] = $open->groupBy(function ($t) use ($hours, $now) {
            $d = $hours($t->created_at, $now) / 24;

            return $d <= 7 ? '0–7 days' : ($d <= 30 ? '8–30 days' : ($d <= 90 ? '31–90 days' : '90+ days'));
        })->map(fn ($r, $label) => ['label' => $label, 'count' => $r->count()])->values();
        $trend = [];
        foreach ($tickets as $t) {
            $period = $t->created_at->copy()->timezone('Asia/Manila')->startOfWeek()->toDateString();
            $trend[$period] ??= ['period' => $period, 'created' => 0, 'resolved' => 0];
            $trend[$period]['created']++;
            if ($t->status === 'done' && $t->resolved_at) {
                $period = $t->resolved_at->copy()->timezone('Asia/Manila')->startOfWeek()->toDateString();
                $trend[$period] ??= ['period' => $period, 'created' => 0, 'resolved' => 0];
                $trend[$period]['resolved']++;
            }
        }ksort($trend);
        $workload = $tickets->groupBy(fn ($t) => $t->assigned_to ?? 'unassigned')->map(function ($rows) use ($avg, $hours) {
            $done = $rows->where('status', 'done')->filter(fn ($t) => $t->resolved_at);

            return ['name' => $rows->first()->assignee?->name ?? 'Unassigned', 'open' => $rows->whereNotIn('status', ['done', 'declined'])->count(), 'resolved' => $done->count(), 'avg_resolution_hours' => $avg($done->map(fn ($t) => $hours($t->created_at, $t->resolved_at))->all())];
        })->values();

        return inertia('admin/TicketAnalytics', ['filters' => $filters, 'options' => ['departments' => SystemTicket::whereNotNull('department')->distinct()->pluck('department'), 'assignees' => User::where('is_active', true)->whereIn('role', config('hr.internal_roles'))->get(['id', 'name'])], 'metrics' => ['created' => $tickets->count(), 'resolved' => $done->count(), 'unresolved' => $open->count(), 'unassigned' => $open->whereNull('assigned_to')->count(), 'reopened' => $reopened, 'documentation_coverage' => $done->count() ? round($done->filter(fn ($t) => ! empty($t->resolution_report['resolution']))->count() / $done->count() * 100, 1) : null, 'first_response_avg_hours' => $avg($responses), 'first_response_median_hours' => $median($responses), 'assignment_avg_hours' => $avg($assignments), 'resolution_avg_hours' => $avg($resolutions), 'resolution_median_hours' => $median($resolutions), 'overdue_response' => $overdue, 'total_with_response_target' => $targets], 'trends' => array_values($trend), 'groups' => $groups, 'workload' => $workload, 'timings' => array_slice($timings,0,100)]);
    }
}
