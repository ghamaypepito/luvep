<?php

namespace App\EpAgent;

use App\Models\User;
use App\Services\Claude\ClaudeSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminOverview
{
    public const STATES = ['blocked', 'reserved', 'running', 'succeeded', 'failed_unsent', 'uncertain'];

    private const METER_FIELDS = ['input_tokens', 'output_tokens', 'cached_read_tokens', 'cached_write_tokens', 'tool_units'];

    public function authorize(User $user): User
    {
        app(Pilot::class)->requireUser($user);
        $fresh = User::findOrFail($user->id);
        abort_unless($fresh->isAdmin(), 403, 'ep_access_denied');

        return $fresh;
    }

    private function start(string $period): Carbon
    {
        abort_unless(in_array($period, ['month', '7d', '30d'], true), 422, 'ep_invalid_period');

        return match ($period) {
            '7d' => now('UTC')->subDays(7),'30d' => now('UTC')->subDays(30),default => now('UTC')->startOfMonth()
        };
    }

    private function query()
    {
        return DB::table('ep_runs as r')->whereIn('r.portal', array_intersect(['education', 'immigration'], config('ep_agent.portals', [])));
    }

    public function overview(User $user, string $period = 'month'): array
    {
        $user = $this->authorize($user);
        $start = $this->start($period);

        return DB::transaction(function () use ($user, $start, $period) {
            $query = $this->query()->where('r.created_at', '>=', $start);
            $states = [];
            foreach (self::STATES as $state) {
                $states[$state] = (clone $query)->where('r.state', $state)->count();
            }
            $ledger = DB::table('ep_usage_reservations as u')->join('ep_budget_accounts as a', 'a.id', '=', 'u.account_id')->where('a.currency', 'USD');
            $window = (clone $ledger)->where('u.created_at', '>=', $start);
            $sum = fn (string $field) => (clone $window)->where('u.state', 'reconciled')->whereNotNull('u.actual_micros')->sum('u.'.$field);
            $meters = [];
            $coverage = [];
            $reconciled = (clone $window)->where('u.state', 'reconciled');
            foreach (self::METER_FIELDS as $field) {
                $missing = (clone $reconciled)->whereNull('u.'.$field)->count();
                $recorded = (int) (clone $reconciled)->sum('u.'.$field);
                $meters[$field] = $missing ? null : $recorded;
                $coverage[$field] = ['recorded' => $recorded, 'missing_entries' => $missing];
            }
            $unknownUsage = (clone $reconciled)->where(function ($query) {
                foreach (self::METER_FIELDS as $field) {
                    $query->orWhereNull('u.'.$field);
                }
            })->count();
            $open = (clone $ledger)->whereIn('u.state', ['reserved', 'sent', 'uncertain']);
            $budgets = DB::table('ep_budget_accounts')->orderByDesc('id')->limit(21)->get();
            $connector = ['can_manage' => $user->isSuperAdmin(), 'ep_transport_connected' => false, 'paid_enabled' => false];
            if ($user->isSuperAdmin()) {
                $settings = app(ClaudeSettings::class);
                $connector += ['legacy_configured' => $settings->apiKey() !== '', 'legacy_staff_enabled' => $settings->enabled('staff'), 'legacy_staff_model' => $settings->model('staff'), 'settings_url' => '/admin/ai-connectors'];
            }

            return ['as_of' => now('UTC')->toIso8601String(), 'period' => $period, 'period_start' => $start->toIso8601String(), 'paid_enabled' => false,
                'runs' => ['total' => (clone $query)->count(), 'states' => $states],
                'ledger' => array_merge(['actual_micros' => (string) $sum('actual_micros'),
                    'reconciled_count' => (clone $reconciled)->count(),
                    'unknown_usage_count' => $unknownUsage, 'meter_coverage' => $coverage,
                    'open_hold_micros' => (string) (clone $open)->sum('u.upper_bound_micros'), 'unresolved_count' => (clone $open)->count(),
                    'unknown_cost_count' => (clone $window)->where('u.state', 'reconciled')->whereNull('u.actual_micros')->count(),
                    'unattributed_count' => (clone $window)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ep_runs')->whereColumn('ep_runs.reservation_id', 'u.id'))->count()], $meters),
                'budgets' => ['data' => $budgets->take(20)->map(fn ($a) => $this->budget($a))->values(), 'has_more' => $budgets->count() > 20, 'total' => DB::table('ep_budget_accounts')->count()],
                'connector' => $connector];
        });
    }

    private function budget(object $account): array
    {
        $status = match (true) {
            $account->currency !== 'USD' => 'unsupported_currency',(bool) $account->frozen => 'frozen',Carbon::parse($account->ends_at, 'UTC')->lte(now('UTC')) => 'expired',Carbon::parse($account->starts_at, 'UTC')->gt(now('UTC')) => 'scheduled',default => 'active'
        };
        $limit = (int) $account->limit_micros;
        $spent = (int) $account->spent_micros;
        $held = (int) $account->held_micros;
        $remaining = max(0, $limit - min($limit, $spent));
        $remaining = max(0, $remaining - min($remaining, $held));

        return ['id' => (int) $account->id, 'currency' => $account->currency, 'starts_at' => Carbon::parse($account->starts_at, 'UTC')->toIso8601String(), 'ends_at' => Carbon::parse($account->ends_at, 'UTC')->toIso8601String(),
            'status' => $status, 'limit_micros' => (string) $limit, 'spent_micros' => (string) $spent, 'held_micros' => (string) $held, 'available_micros' => $status === 'active' ? (string) $remaining : '0'];
    }

    public function runs(User $user, string $period = 'month', ?string $portal = null, ?string $state = null, ?int $before = null): array
    {
        $this->authorize($user);
        $start = $this->start($period);
        abort_if($portal !== null && ! in_array($portal, ['education', 'immigration'], true), 422, 'ep_invalid_portal');
        abort_if($state !== null && ! in_array($state, self::STATES, true), 422, 'ep_invalid_state');
        $query = $this->query()->where('r.created_at', '>=', $start);
        if ($portal) {
            $query->where('r.portal', $portal);
        }if ($state) {
            $query->where('r.state', $state);
        }if ($before) {
            $query->where('r.id', '<', $before);
        }
        $rows = $this->rows($query)->orderByDesc('r.id')->limit(26)->get();
        $more = $rows->count() > 25;
        $rows = $rows->take(25);

        return ['data' => $rows->map(fn ($r) => $this->publicRun($r))->values(), 'next_before' => $more ? (int) $rows->last()->id : null];
    }

    public function run(User $user, int $id): array
    {
        $this->authorize($user);
        $row = $this->rows($this->query()->where('r.id', $id))->first();
        abort_unless($row, 404, 'ep_not_found');

        return $this->publicRun($row);
    }

    private function rows($query)
    {
        return $query->leftJoin('users as actor', 'actor.id', '=', 'r.actor_id')->leftJoin('ep_usage_reservations as u', 'u.id', '=', 'r.reservation_id')->leftJoin('ep_budget_accounts as a', 'a.id', '=', 'u.account_id')
            ->select(['r.id', 'r.actor_id', 'r.portal', 'r.state', 'r.reason', 'r.created_at', 'r.updated_at', 'actor.name as actor_name', 'actor.deleted_at as actor_deleted_at',
                'a.currency as currency', 'u.state as usage_state', 'u.actual_micros', 'u.upper_bound_micros', 'u.provider', 'u.model', 'u.pricing_version', ...array_map(fn ($field) => 'u.'.$field, self::METER_FIELDS)]);
    }

    private function publicRun(object $row): array
    {
        $data = ['id' => (int) $row->id, 'actor' => ['id' => (int) $row->actor_id, 'name' => $row->actor_deleted_at || ! $row->actor_name ? 'Former staff' : $row->actor_name],
            'portal' => $row->portal, 'state' => in_array($row->state, self::STATES, true) ? $row->state : 'unknown',
            'reason_label' => match ($row->reason) {
                null => null,'provider_not_activated' => 'Provider not activated','budget_exhausted' => 'Budget exhausted',default => 'Status requires review'
            },
            'created_at' => Carbon::parse($row->created_at, 'UTC')->toIso8601String(), 'updated_at' => Carbon::parse($row->updated_at, 'UTC')->toIso8601String(),
            'actual_micros' => $row->actual_micros === null ? null : (string) $row->actual_micros, 'upper_bound_micros' => $row->upper_bound_micros === null ? null : (string) $row->upper_bound_micros,
            'currency' => $row->currency, 'usage_state' => $row->usage_state, 'provider' => $row->provider, 'model' => $row->model, 'pricing_version' => $row->pricing_version];
        foreach (self::METER_FIELDS as $field) {
            $data[$field] = $row->$field === null ? null : (int) $row->$field;
        }

        return $data;
    }
}
