<?php

namespace App\EpAgent;

use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Internal accounting API. No public endpoint accepts budgets, prices or cost bounds. */
class BudgetLedger
{
    private const MAX_MICROS = 1_000_000_000_000;

    private const UNITS = ['input_tokens', 'output_tokens', 'cached_read_tokens', 'cached_write_tokens', 'tool_units'];

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new DomainException('ep_'.$code);
        }
    }

    public function reserve(int $accountId, string $idempotencyKey, int $upperBoundMicros): object
    {
        $this->require($upperBoundMicros > 0 && $upperBoundMicros <= self::MAX_MICROS, 'invalid_bound');
        $this->require((bool) preg_match('/^[A-Za-z0-9:_-]{1,128}$/D', $idempotencyKey), 'invalid_key');

        return DB::transaction(function () use ($accountId, $idempotencyKey, $upperBoundMicros) {
            $account = DB::table('ep_budget_accounts')->where('id', $accountId)->lockForUpdate()->first();
            $this->require($account !== null, 'budget_missing');
            $existing = DB::table('ep_usage_reservations')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                $this->require((int) $existing->account_id === $accountId && (int) $existing->upper_bound_micros === $upperBoundMicros, 'key_conflict');

                return $existing;
            }
            $this->require($account->currency === 'USD' && ! $account->frozen
                && $account->starts_at <= now()->toDateTimeString() && $account->ends_at > now()->toDateTimeString(), 'budget_inactive');
            $this->require($account->limit_micros <= self::MAX_MICROS && $account->limit_micros >= 0
                && $account->spent_micros >= 0 && $account->held_micros >= 0, 'invalid_balance');
            $remaining = (int) $account->limit_micros - (int) $account->spent_micros - (int) $account->held_micros;
            $this->require($upperBoundMicros <= $remaining, 'budget_exhausted');
            $changed = DB::table('ep_budget_accounts')->where('id', $accountId)
                ->whereRaw('spent_micros + held_micros + ? <= limit_micros', [$upperBoundMicros])
                ->increment('held_micros', $upperBoundMicros);
            $this->require($changed === 1, 'budget_exhausted');
            $id = DB::table('ep_usage_reservations')->insertGetId([
                'account_id' => $accountId, 'idempotency_key' => $idempotencyKey, 'state' => 'reserved',
                'upper_bound_micros' => $upperBoundMicros, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('ep_usage_reservations')->where('id', $id)->first();
        }, 3);
    }

    /** Must commit before sending; a crash after this point is never refunded automatically. */
    public function markDispatched(int $reservationId): object
    {
        return $this->locked($reservationId, function ($account, $hold) {
            $this->require($hold->state === 'reserved', 'invalid_transition');
            $this->require(! $account->frozen && $account->ends_at > now()->toDateTimeString(), 'budget_inactive');
            $this->update($hold->id, ['state' => 'sent']);
        });
    }

    public function markUncertain(int $reservationId): object
    {
        return $this->locked($reservationId, function ($account, $hold) {
            $this->require(in_array($hold->state, ['sent', 'uncertain'], true), 'invalid_transition');
            $this->update($hold->id, ['state' => 'uncertain']);
        });
    }

    public function releaseUnsent(int $reservationId): object
    {
        return $this->locked($reservationId, function ($account, $hold) {
            if ($hold->state === 'released') {
                return;
            }
            $this->require($hold->state === 'reserved', 'not_proven_unsent');
            $this->require((int) $account->held_micros >= (int) $hold->upper_bound_micros, 'invalid_balance');
            DB::table('ep_budget_accounts')->where('id', $account->id)->decrement('held_micros', $hold->upper_bound_micros);
            $this->update($hold->id, ['state' => 'released']);
        });
    }

    public function reconcile(int $reservationId, int $actualMicros, array $usage): object
    {
        $this->require($actualMicros >= 0 && $actualMicros <= self::MAX_MICROS, 'invalid_cost');
        $required = array_merge(['provider', 'model', 'pricing_version'], self::UNITS);
        $keys = array_keys($usage);
        sort($keys);
        sort($required);
        $this->require($keys === $required, 'unknown_usage');
        foreach (['provider', 'model', 'pricing_version'] as $key) {
            $this->require(is_string($usage[$key]) && strlen($usage[$key]) > 0 && strlen($usage[$key]) <= ($key === 'provider' ? 64 : 128), 'unknown_usage');
        }
        foreach (self::UNITS as $unit) {
            $this->require(is_int($usage[$unit]) && $usage[$unit] >= 0 && $usage[$unit] <= 1_000_000_000, 'unknown_usage');
        }

        $result = $this->locked($reservationId, function ($account, $hold) use ($actualMicros, $usage) {
            if ($hold->state === 'reconciled') {
                $this->require((int) $hold->actual_micros === $actualMicros, 'reconciliation_conflict');
                foreach ($usage as $key => $value) {
                    $this->require(in_array($key, self::UNITS, true) ? (int) $hold->$key === $value : $value === $hold->$key, 'reconciliation_conflict');
                }

                return;
            }
            $this->require(in_array($hold->state, ['sent', 'uncertain'], true), 'invalid_transition');
            $price = config('ep_agent.price_versions', [])[$usage['pricing_version']] ?? null;
            $this->require(is_array($price) && ($price['provider'] ?? null) === $usage['provider']
                && ($price['model'] ?? null) === $usage['model'], 'unknown_price');
            $numerator = 0;
            foreach (self::UNITS as $unit) {
                $rate = $price['rates_per_million'][$unit] ?? null;
                $this->require(is_int($rate) && $rate >= 0 && $rate <= 1_000_000_000, 'unknown_price');
                $numerator += $usage[$unit] * $rate;
            }
            // Both factors are bounded at 1e9, five terms fit a signed 64-bit integer.
            $expected = intdiv($numerator + 999999, 1000000);
            $this->require($expected === $actualMicros, 'cost_mismatch');
            $this->require((int) $account->held_micros >= (int) $hold->upper_bound_micros
                && (int) $account->spent_micros <= PHP_INT_MAX - $actualMicros, 'invalid_balance');
            $overBound = $actualMicros > (int) $hold->upper_bound_micros;
            DB::table('ep_budget_accounts')->where('id', $account->id)->update([
                'held_micros' => (int) $account->held_micros - (int) $hold->upper_bound_micros,
                'spent_micros' => (int) $account->spent_micros + $actualMicros,
                'frozen' => (bool) $account->frozen || $overBound,
            ]);
            $this->update($hold->id, array_merge($usage, [
                'actual_micros' => $actualMicros, 'state' => 'reconciled', 'price_snapshot' => json_encode($price, JSON_THROW_ON_ERROR),
            ]));
        });
        // The anomaly must be committed before raising; rolling back would hide a real charge.
        $this->require($actualMicros <= (int) $result->upper_bound_micros, 'bound_exceeded_account_frozen');

        return $result;
    }

    private function locked(int $id, Closure $change): object
    {
        return DB::transaction(function () use ($id, $change) {
            $initial = DB::table('ep_usage_reservations')->where('id', $id)->first();
            $this->require($initial !== null, 'reservation_missing');
            // All transitions lock account then reservation, matching reserve's lock order.
            $account = DB::table('ep_budget_accounts')->where('id', $initial->account_id)->lockForUpdate()->first();
            $this->require($account !== null, 'budget_missing');
            $hold = DB::table('ep_usage_reservations')->where('id', $id)->lockForUpdate()->first();
            $change($account, $hold);

            return DB::table('ep_usage_reservations')->where('id', $id)->first();
        }, 3);
    }

    private function update(int $id, array $values): void
    {
        DB::table('ep_usage_reservations')->where('id', $id)->update(array_merge($values, ['updated_at' => now()]));
    }
}
