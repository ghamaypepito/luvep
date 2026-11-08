<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\FinancePayable;
use App\Models\FinancePayment;
use App\Models\FinanceReceivable;
use App\Services\Reports\ReportWeek;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Finance reports — built entirely from the AR/AP ledger, so every figure ties
 * back to an invoice, bill or payment (no synthesised numbers). Income statement
 * is cash-basis (money actually received/paid in the window); aging reports are
 * a live snapshot of what's still outstanding.
 */
class FinanceReportController extends Controller
{
    private const REPORTS = ['income', 'receivables_aging', 'payables_aging', 'collections'];

    public function index(Request $request)
    {
        $report = $request->query('report', 'income');
        if (! in_array($report, self::REPORTS, true)) {
            $report = 'income';
        }

        $week = ReportWeek::forPreset($request->input('preset'));
        [$weekStart, $weekEnd] = $week?->dates() ?? [null, null];
        $tz = config('app.timezone', 'UTC');
        $start = Carbon::parse($weekStart ?? ($request->query('start') ?: now($tz)->startOfMonth()))->startOfDay();
        $end = Carbon::parse($weekEnd ?? ($request->query('end') ?: now($tz)->endOfMonth()))->endOfDay();

        $data = match ($report) {
            'receivables_aging' => $this->aging(FinanceReceivable::class),
            'payables_aging' => $this->aging(FinancePayable::class),
            'collections' => $this->collections($start, $end),
            default => $this->income($start, $end),
        };

        return inertia('portal/finance/Reports', [
            'report' => $report,
            'preset' => $week?->preset,
            'weeklyReport' => $week && ! str_ends_with($report, '_aging') && ReportWeek::canUseNotes($request->user()) ? $week->notes('finance') + ['tasks' => $week->tasks('finance')] : null,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'currency' => strtoupper(config('services.booking.currency', 'nzd')),
            'data' => $data,
        ]);
    }

    /** Cash-basis P&L: revenue received minus expenses paid, in the window. */
    private function income(Carbon $start, Carbon $end): array
    {
        $revenue = (float) FinancePayment::where('paymentable_type', FinanceReceivable::class)
            ->whereBetween('paid_on', [$start, $end])->sum('amount');

        $expensePayments = FinancePayment::where('paymentable_type', FinancePayable::class)
            ->whereBetween('paid_on', [$start, $end])->get(['paymentable_id', 'amount']);
        $expenses = (float) $expensePayments->sum('amount');

        $cats = FinancePayable::whereIn('id', $expensePayments->pluck('paymentable_id')->unique())
            ->pluck('category', 'id');
        $breakdown = $expensePayments
            ->groupBy(fn ($p) => ($cats[$p->paymentable_id] ?? null) ?: 'Uncategorised')
            ->map(fn ($g) => round((float) $g->sum('amount'), 2))
            ->sortDesc()
            ->map(fn ($amount, $category) => ['category' => $category, 'amount' => $amount])
            ->values()->all();

        return [
            'revenue' => round($revenue, 2),
            'expenses' => round($expenses, 2),
            'net' => round($revenue - $expenses, 2),
            'expense_breakdown' => $breakdown,
        ];
    }

    /** Live aging snapshot for a receivable/payable model. */
    private function aging(string $model): array
    {
        $rows = $model::with('payments')->get()
            ->filter(fn ($r) => ! in_array($r->payment_status, ['paid', 'void'], true) && $r->balance > 0.005);

        $buckets = ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        $list = [];
        foreach ($rows as $r) {
            $buckets[$r->aging_bucket] += $r->balance;
            $list[] = [
                'ref' => $r->invoice_no ?? $r->bill_no,
                'party' => $r->client_name ?? $r->vendor_name,
                'due_date' => optional($r->due_date)->toDateString(),
                'amount' => (float) $r->amount,
                'balance' => $r->balance,
                'days_past_due' => $r->days_past_due,
                'bucket' => $r->aging_bucket,
                'currency' => $r->currency,
            ];
        }
        usort($list, fn ($a, $b) => $b['days_past_due'] <=> $a['days_past_due']);

        return [
            'buckets' => array_map(fn ($v) => round($v, 2), $buckets),
            'total' => round(array_sum($buckets), 2),
            'rows' => $list,
        ];
    }

    /** Cash in vs cash out over the window. */
    private function collections(Carbon $start, Carbon $end): array
    {
        $in = FinancePayment::where('paymentable_type', FinanceReceivable::class)
            ->whereBetween('paid_on', [$start, $end])->orderBy('paid_on')->get();
        $out = FinancePayment::where('paymentable_type', FinancePayable::class)
            ->whereBetween('paid_on', [$start, $end])->orderBy('paid_on')->get();

        $recv = FinanceReceivable::whereIn('id', $in->pluck('paymentable_id')->unique())->get()->keyBy('id');
        $pay = FinancePayable::whereIn('id', $out->pluck('paymentable_id')->unique())->get()->keyBy('id');

        $received = $in->map(fn ($p) => [
            'paid_on' => optional($p->paid_on)->toDateString(),
            'ref' => optional($recv->get($p->paymentable_id))->invoice_no,
            'party' => optional($recv->get($p->paymentable_id))->client_name,
            'method' => $p->method,
            'amount' => (float) $p->amount,
        ])->values();

        $paid = $out->map(fn ($p) => [
            'paid_on' => optional($p->paid_on)->toDateString(),
            'ref' => optional($pay->get($p->paymentable_id))->bill_no,
            'party' => optional($pay->get($p->paymentable_id))->vendor_name,
            'method' => $p->method,
            'amount' => (float) $p->amount,
        ])->values();

        $receivedTotal = round((float) $in->sum('amount'), 2);
        $paidTotal = round((float) $out->sum('amount'), 2);

        return [
            'received' => $received,
            'paid' => $paid,
            'received_total' => $receivedTotal,
            'paid_total' => $paidTotal,
            'net' => round($receivedTotal - $paidTotal, 2),
        ];
    }
}
