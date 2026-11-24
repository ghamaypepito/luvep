<?php

namespace Tests\Feature\Finance;

use App\Models\FinancePayable;
use App\Models\FinancePayment;
use App\Models\FinanceReceivable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinanceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-23 03:00:00', 'UTC'));
        $this->actingAs(User::factory()->create(['role' => 'finance']));
    }

    public function test_analytics_uses_payment_dates_exact_currency_and_the_same_ledger_as_detail_reports(): void
    {
        $ar = $this->receivable(['notes' => '[afirmo-source:INVOICE:INVOICE-100]', 'due_date' => '2026-11-01']);
        foreach (['2026-11-01' => 50.05, '2026-11-02' => 100.10, '2026-11-15' => 200.20, '2026-11-16' => 75.75] as $date => $amount) {
            $ar->payments()->create(['paid_on' => $date, 'amount' => $amount]);
        }
        $ap = FinancePayable::create(['bill_no' => 'BILL-100', 'vendor_name' => 'Supplier', 'amount' => 500, 'currency' => 'NZD', 'status' => 'approved', 'category' => 'Hosting', 'issue_date' => '2026-01-01', 'due_date' => '2026-12-01']);
        $ap->payments()->create(['paid_on' => '2026-11-03', 'amount' => 10.05]);
        $ap->payments()->create(['paid_on' => '2026-11-15', 'amount' => 3.01]);
        $this->receivable(['currency' => 'USD'])->payments()->create(['paid_on' => '2026-11-04', 'amount' => 999]);
        $this->receivable(['status' => 'draft', 'due_date' => '2026-09-01']);
        // A voided invoice's existing receipt is still a recorded cash movement.
        $this->receivable(['status' => 'void'])->payments()->create(['paid_on' => '2026-11-10', 'amount' => 10]);
        FinancePayment::create(['paymentable_type' => FinanceReceivable::class, 'paymentable_id' => 999999, 'paid_on' => '2026-11-10', 'amount' => 999]);
        $counts = [FinanceReceivable::count(), FinancePayable::count(), FinancePayment::count()];
        $this->get('/portal/finance/reports?start=2026-11-02&end=2026-11-15')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('report', 'analytics')->where('currency', 'NZD')
            ->where('data.received', 310.3)->where('data.paid', 13.06)->where('data.net', 297.24)
            ->where('data.previous.start', '2026-10-19')->where('data.previous.end', '2026-11-01')->where('data.previous.received', 50.05)
            ->where('data.trends.0.period', '2026-11-02')->where('data.trends.0.received', 100.1)
            ->where('data.trends.1.received', 210.2)->where('data.trends.1.paid', 3.01)
            ->where('data.expense_breakdown.0.category', 'Hosting')->where('data.expense_breakdown.0.amount', 13.06)
            ->where('data.receivables.total', 573.9)->where('data.receivables.overdue_count', 1)
            ->where('data.receivables.attention.0.id', $ar->id)->where('data.payables.total', 486.94)
            ->where('data.as_of', '2026-11-23'));
        $this->get('/portal/finance/reports?report=collections&start=2026-11-02&end=2026-11-15')
            ->assertInertia(fn (Assert $page) => $page->where('data.received_total', 310.3)->where('data.paid_total', 13.06));
        $this->assertSame($counts, [FinanceReceivable::count(), FinancePayable::count(), FinancePayment::count()]);
    }

    public function test_live_aging_distinguishes_due_soon_and_overdue_and_excludes_drafts_voids_and_overpayments(): void
    {
        foreach ([0, 1, 30, 31, 60, 61, 90, 91] as $days) {
            $this->receivable(['amount' => 100, 'due_date' => today()->subDays($days)]);
        }
        $this->receivable(['amount' => 80, 'due_date' => today()->addDays(6)]);
        $this->receivable(['amount' => 90, 'due_date' => today()->addDays(7)]);
        $this->receivable(['amount' => 999, 'status' => 'draft']);
        $this->receivable(['amount' => 999, 'status' => 'void']);
        $this->receivable(['amount' => 10])->payments()->create(['amount' => 20, 'paid_on' => today()]);
        $this->get('/portal/finance/reports?start=2026-10-01&end=2026-10-31')
            ->assertInertia(fn (Assert $page) => $page->where('data.receivables.total', 970)
                ->where('data.receivables.buckets.current', 270)->where('data.receivables.buckets.1-30', 200)
                ->where('data.receivables.buckets.31-60', 200)->where('data.receivables.buckets.61-90', 200)
                ->where('data.receivables.buckets.90+', 100)->where('data.receivables.overdue_total', 700)
                ->where('data.receivables.due_soon_total', 180)
                ->where('data.receivables.oldest_days', 91)->has('data.receivables.attention', 5));
        $this->get('/portal/finance/reports?report=receivables_aging')
            ->assertInertia(fn (Assert $page) => $page->where('data.total', 970)->has('data.rows', 10));
    }

    public function test_empty_period_retains_zero_weeks_and_week_presets_and_currency_switches_work(): void
    {
        $this->get('/portal/finance/reports?start=2026-11-01&end=2026-11-30')
            ->assertInertia(fn (Assert $page) => $page->where('data.received', 0)->where('data.net', 0)
                ->has('data.trends', 6)->where('data.trends.0.received', 0)->has('data.expense_breakdown', 0));
        $this->receivable(['currency' => 'USD'])->payments()->create(['amount' => 12.34, 'paid_on' => today()]);
        $this->get('/portal/finance/reports?preset=this_week&currency=USD')
            ->assertInertia(fn (Assert $page) => $page->where('preset', 'this_week')->where('currency', 'USD')
                ->where('start', '2026-11-23')->where('end', '2026-11-23')->where('data.received', 12.34));
        $this->get('/portal/finance/reports?currency=BAD')->assertInertia(fn (Assert $page) => $page->where('currency', 'NZD'));
    }

    public function test_date_validation_and_existing_finance_access_are_enforced(): void
    {
        foreach ([['start' => 'not-a-date'], ['start' => '2026-02-30'], ['start' => '2026-12-01', 'end' => '2026-11-01'], ['start' => '2000-01-01', 'end' => '2026-11-23']] as $query) {
            $this->get('/portal/finance/reports?'.http_build_query($query))->assertSessionHasErrors();
        }
        $this->actingAs(User::factory()->create(['role' => 'lead']));
        $this->get('/portal/finance/reports')->assertForbidden();
    }

    public function test_payment_status_pies_partition_issued_value_in_range_without_counting_overdue_twice(): void
    {
        foreach (['receivables' => FinanceReceivable::class, 'payables' => FinancePayable::class] as $key => $model) {
            $make = fn (array $attributes) => $model::create(array_replace([
                $key === 'receivables' ? 'invoice_no' : 'bill_no' => 'PIE-'.($model::count() + 1),
                $key === 'receivables' ? 'client_name' : 'vendor_name' => 'Synthetic Party',
                'amount' => 100, 'currency' => 'NZD', 'status' => $key === 'receivables' ? 'sent' : 'approved',
                'issue_date' => '2026-11-01', 'due_date' => today(),
            ], $attributes));
            foreach ([
                ['amount' => 100.01, 'due_date' => today()->subDay(), 'paid' => 20.01],
                ['amount' => 250, 'paid' => 50],
                ['amount' => 50, 'due_date' => today()->addDay(), 'paid' => 0],
                ['amount' => 100, 'paid' => 100],
                ['amount' => 40, 'paid' => 60],
                ['amount' => 999, 'status' => 'draft', 'paid' => 50],
                ['amount' => 888, 'status' => 'void', 'paid' => 50],
                ['amount' => 777, 'currency' => 'USD', 'paid' => 50],
            ] as $attributes) {
                $paid = $attributes['paid'];
                unset($attributes['paid']);
                $record = $make($attributes);
                if ($paid) {
                    $record->payments()->create(['amount' => $paid, 'paid_on' => '2026-11-02']);
                }
            }
            $this->get('/portal/finance/reports?start=2026-11-01&end=2026-11-30')
                ->assertInertia(fn (Assert $page) => $page
                    ->where("data.{$key}.period_status.paid_total", 210.01)
                    ->where("data.{$key}.period_status.not_overdue_total", 250)
                    ->where("data.{$key}.period_status.overdue_total", 80)
                    ->where("data.{$key}.period_status.issued_total", 540.01)
                    ->where("data.{$key}.total", 330));
            $this->get('/portal/finance/reports?currency=USD')
                ->assertInertia(fn (Assert $page) => $page->where("data.{$key}.period_status.paid_total", 50)
                    ->where("data.{$key}.period_status.not_overdue_total", 727)->where("data.{$key}.period_status.overdue_total", 0)
                    ->where("data.{$key}.period_status.issued_total", 777));
        }
    }

    public function test_payment_status_pies_follow_issue_dates_and_the_historical_cutoff(): void
    {
        foreach (['receivables' => FinanceReceivable::class, 'payables' => FinancePayable::class] as $key => $model) {
            $make = fn ($issued, $due, $amount) => $model::create([
                $key === 'receivables' ? 'invoice_no' : 'bill_no' => 'RANGE-'.($model::count() + 1),
                $key === 'receivables' ? 'client_name' : 'vendor_name' => 'Synthetic Party',
                'amount' => $amount, 'currency' => 'NZD', 'status' => $key === 'receivables' ? 'sent' : 'approved',
                'issue_date' => $issued, 'due_date' => $due,
            ]);
            $make('2026-10-31', '2026-11-01', 900);
            $first = $make('2026-11-01', '2026-11-05', 100.01);
            $first->payments()->create(['amount' => 20.01, 'paid_on' => '2026-11-10']);
            $first->payments()->create(['amount' => 50, 'paid_on' => '2026-11-16']);
            $last = $make('2026-11-15', '2026-11-15', 200);
            $last->payments()->create(['amount' => 30, 'paid_on' => '2026-11-15']);
            $last->payments()->create(['amount' => 70, 'paid_on' => '2026-11-16']);
            $make('2026-11-16', '2026-12-01', 700);
            $make('2026-11-24', '2026-12-01', 500);

            $this->get('/portal/finance/reports?start=2026-11-01&end=2026-11-15')
                ->assertInertia(fn (Assert $page) => $page->where('data.status_as_of', '2026-11-15')
                    ->where("data.{$key}.period_status.paid_total", 50.01)
                    ->where("data.{$key}.period_status.not_overdue_total", 170)
                    ->where("data.{$key}.period_status.overdue_total", 80)
                    ->where("data.{$key}.period_status.issued_total", 300.01)
                    ->where("data.{$key}.total", 2230));
            $this->get('/portal/finance/reports?start=2026-11-15&end=2026-11-30')
                ->assertInertia(fn (Assert $page) => $page->where('data.status_as_of', '2026-11-23')
                    ->where("data.{$key}.period_status.paid_total", 100)
                    ->where("data.{$key}.period_status.not_overdue_total", 700)
                    ->where("data.{$key}.period_status.overdue_total", 100)
                    ->where("data.{$key}.period_status.issued_total", 900));
            $this->get('/portal/finance/reports?start=2026-11-24&end=2026-11-30')
                ->assertInertia(fn (Assert $page) => $page->where("data.{$key}.period_status.issued_total", 0));
        }
    }

    private function receivable(array $attributes = []): FinanceReceivable
    {
        return FinanceReceivable::create(array_replace(['invoice_no' => 'TEST-'.(FinanceReceivable::count() + 1), 'client_name' => 'Synthetic Client', 'amount' => 1000,
            'issue_date' => '2026-01-01', 'due_date' => '2026-12-01', 'currency' => 'NZD', 'status' => 'sent'], $attributes));
    }
}
