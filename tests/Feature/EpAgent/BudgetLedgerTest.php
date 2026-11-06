<?php

namespace Tests\Feature\EpAgent;

use App\EpAgent\BudgetLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BudgetLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ep_agent.price_versions' => ['fixture-v1' => [
            'provider' => 'fixture', 'model' => 'test-model',
            'rates_per_million' => ['input_tokens' => 1000000, 'output_tokens' => 2000000, 'cached_read_tokens' => 1000000, 'cached_write_tokens' => 1000000, 'tool_units' => 1000000],
        ]]]);
    }

    private function account(int $limit = 100): int
    {
        return DB::table('ep_budget_accounts')->insertGetId([
            'currency' => 'USD', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addMonth(),
            'limit_micros' => $limit, 'spent_micros' => 0, 'held_micros' => 0, 'frozen' => false,
        ]);
    }

    private function usage(array $changes = []): array
    {
        return array_merge(['provider' => 'fixture', 'model' => 'test-model', 'pricing_version' => 'fixture-v1',
            'input_tokens' => 10, 'output_tokens' => 5, 'cached_read_tokens' => 0, 'cached_write_tokens' => 0, 'tool_units' => 0], $changes);
    }

    private function denies(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected ledger rejection');
        } catch (\DomainException $e) {
            $this->assertStringStartsWith('ep_', $e->getMessage());
        }
    }

    public function test_reservation_requires_valid_budget_amount_and_period(): void
    {
        $ledger = app(BudgetLedger::class);
        $this->denies(fn () => $ledger->reserve(999, 'missing', 10));
        $id = $this->account();
        foreach ([0, -1, PHP_INT_MAX] as $amount) {
            $this->denies(fn () => $ledger->reserve($id, 'bad', $amount));
        }
        DB::table('ep_budget_accounts')->where('id', $id)->update(['ends_at' => now()->subSecond()]);
        $this->denies(fn () => $ledger->reserve($id, 'expired', 1));
    }

    public function test_parallel_style_reservations_cannot_overcommit_remaining_budget(): void
    {
        $ledger = app(BudgetLedger::class);
        $id = $this->account();
        $ledger->reserve($id, 'first', 60);
        $this->denies(fn () => $ledger->reserve($id, 'second', 60));
        $ledger->reserve($id, 'fits', 40);
        $this->denies(fn () => $ledger->reserve($id, 'overflow', 1));
        $this->assertDatabaseHas('ep_budget_accounts', ['id' => $id, 'spent_micros' => 0, 'held_micros' => 100]);
    }

    public function test_idempotent_replay_does_not_hold_twice_and_mismatched_reuse_is_rejected(): void
    {
        $ledger = app(BudgetLedger::class);
        $id = $this->account();
        $first = $ledger->reserve($id, 'same-key', 50);
        $this->assertSame($first->id, $ledger->reserve($id, 'same-key', 50)->id);
        $this->denies(fn () => $ledger->reserve($id, 'same-key', 40));
        $this->denies(fn () => $ledger->reserve($this->account(), 'same-key', 50));
        $this->assertDatabaseHas('ep_budget_accounts', ['id' => $id, 'held_micros' => 50]);
    }

    public function test_reconcile_preserves_detailed_usage_and_charges_exactly_once(): void
    {
        $ledger = app(BudgetLedger::class);
        $id = $this->account();
        $hold = $ledger->reserve($id, 'once', 60);
        $ledger->markDispatched($hold->id);
        $ledger->reconcile($hold->id, 20, $this->usage());
        $ledger->reconcile($hold->id, 20, $this->usage());
        $this->assertDatabaseHas('ep_budget_accounts', ['id' => $id, 'spent_micros' => 20, 'held_micros' => 0]);
        $this->assertDatabaseHas('ep_usage_reservations', ['id' => $hold->id, 'state' => 'reconciled', 'input_tokens' => 10, 'output_tokens' => 5, 'actual_micros' => 20, 'pricing_version' => 'fixture-v1']);
        $this->denies(fn () => $ledger->reconcile($hold->id, 22, $this->usage(['output_tokens' => 6])));
    }

    public function test_unknown_completion_retains_hold_and_cannot_be_released(): void
    {
        $ledger = app(BudgetLedger::class);
        $id = $this->account();
        $hold = $ledger->reserve($id, 'timeout', 100);
        $ledger->markDispatched($hold->id);
        $ledger->markUncertain($hold->id);
        $ledger->markUncertain($hold->id);
        $this->denies(fn () => $ledger->releaseUnsent($hold->id));
        $this->denies(fn () => $ledger->reserve($id, 'new', 1));
        $ledger->reconcile($hold->id, 20, $this->usage());
        $this->assertDatabaseHas('ep_budget_accounts', ['id' => $id, 'spent_micros' => 20, 'held_micros' => 0]);
    }

    public function test_unsent_release_is_idempotent_but_sent_requests_are_not_refunded(): void
    {
        $ledger = app(BudgetLedger::class);
        $id = $this->account();
        $hold = $ledger->reserve($id, 'unsent', 100);
        $ledger->releaseUnsent($hold->id);
        $ledger->releaseUnsent($hold->id);
        $this->denies(fn () => $ledger->markDispatched($hold->id));
        $this->denies(fn () => $ledger->reconcile($hold->id, 20, $this->usage()));
        $sent = $ledger->reserve($id, 'sent', 100);
        $ledger->markDispatched($sent->id);
        $this->denies(fn () => $ledger->releaseUnsent($sent->id));
        $this->assertDatabaseHas('ep_budget_accounts', ['id' => $id, 'held_micros' => 100]);
    }

    public function test_unknown_usage_or_price_never_becomes_free_or_releases_hold(): void
    {
        $ledger = app(BudgetLedger::class);
        $id = $this->account();
        $hold = $ledger->reserve($id, 'unknown', 100);
        $ledger->markDispatched($hold->id);
        foreach ([[], $this->usage(['pricing_version' => 'unknown']), $this->usage(['model' => 'different']), $this->usage(['input_tokens' => -1]), $this->usage(['tool_units' => null]), $this->usage(['extra_meter' => 1])] as $usage) {
            $this->denies(fn () => $ledger->reconcile($hold->id, 20, $usage));
        }
        $this->denies(fn () => $ledger->reconcile($hold->id, 0, $this->usage()));
        $this->assertDatabaseHas('ep_budget_accounts', ['id' => $id, 'held_micros' => 100, 'spent_micros' => 0]);
    }

    public function test_expired_budget_can_still_reconcile_old_charges(): void
    {
        $ledger = app(BudgetLedger::class);
        $id = $this->account();
        $hold = $ledger->reserve($id, 'old', 100);
        $ledger->markDispatched($hold->id);
        DB::table('ep_budget_accounts')->where('id', $id)->update(['ends_at' => now()->subSecond()]);
        $ledger->reconcile($hold->id, 20, $this->usage());
        $this->assertDatabaseHas('ep_budget_accounts', ['id' => $id, 'spent_micros' => 20, 'held_micros' => 0]);
    }

    public function test_over_bound_actual_charge_is_recorded_and_account_frozen(): void
    {
        $ledger = app(BudgetLedger::class);
        $id = $this->account(10);
        $hold = $ledger->reserve($id, 'over', 10);
        $ledger->markDispatched($hold->id);
        $this->denies(fn () => $ledger->reconcile($hold->id, 20, $this->usage()));
        $this->assertDatabaseHas('ep_budget_accounts', ['id' => $id, 'spent_micros' => 20, 'held_micros' => 0, 'frozen' => true]);
        $this->assertDatabaseHas('ep_usage_reservations', ['id' => $hold->id, 'actual_micros' => 20]);
        $this->denies(fn () => $ledger->reserve($id, 'no-more', 1));
    }
}
