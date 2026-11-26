<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\FinancePayable;
use App\Models\FinancePayment;
use App\Models\FinanceReceivable;
use App\Services\Reports\ReportWeek;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Finance reports — built entirely from the AR/AP ledger, so every figure ties
 * back to an invoice, bill or payment (no synthesised numbers). Income statement
 * is cash-basis (money actually received/paid in the window); aging reports are
 * a live snapshot of what's still outstanding.
 */
class FinanceReportController extends Controller
{
    private const REPORTS = ['analytics', 'income', 'receivables_aging', 'payables_aging', 'collections'];

    public function index(Request $request)
    {
        $report = $request->query('report', 'analytics');
        if (! in_array($report, self::REPORTS, true)) {
            $report = 'analytics';
        }

        $week = ReportWeek::forPreset($request->input('preset'));
        if (! $week) {
            $request->validate(['start' => ['nullable', 'date_format:Y-m-d'], 'end' => ['nullable', 'date_format:Y-m-d']]);
        }
        [$weekStart, $weekEnd] = $week?->dates() ?? [null, null];
        $tz = config('app.timezone', 'UTC');
        $start = Carbon::parse($weekStart ?? ($request->query('start') ?: now($tz)->startOfMonth()))->startOfDay();
        $end = Carbon::parse($weekEnd ?? ($request->query('end') ?: now($tz)->endOfMonth()))->endOfDay();
        if ($end->lt($start) || $start->diffInDays($end) > 1827) {
            throw ValidationException::withMessages(['end' => 'Choose an end date on or after the start, within a five-year reporting window.']);
        }

        $defaultCurrency = strtoupper(config('services.booking.currency', 'nzd'));
        $currencies = FinanceReceivable::distinct()->pluck('currency')
            ->merge(FinancePayable::distinct()->pluck('currency'))
            ->prepend($defaultCurrency)->unique()->values();
        $selected = $request->query('currency');
        $currency = $currencies->containsStrict($selected) ? $selected : $defaultCurrency;
        $data = match ($report) {
            'receivables_aging' => $this->aging(FinanceReceivable::class, $currency),
            'payables_aging' => $this->aging(FinancePayable::class, $currency),
            'collections' => $this->collections($start, $end, $currency),
            'income' => $this->income($start, $end, $currency),
            default => $this->analytics($start, $end, $currency),
        };

        return inertia('portal/finance/Reports', [
            'report' => $report,
            'preset' => $week?->preset,
            'weeklyReport' => $week && ! str_ends_with($report, '_aging') && ReportWeek::canUseNotes($request->user()) ? $week->notes('finance') + ['tasks' => $week->tasks('finance')] : null,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'currency' => $currency,
            'currencies' => $currencies,
            'data' => $data,
        ]);
    }

    /** Period cash and document status, plus a separately labelled live aging snapshot. */
    private function analytics(Carbon $start, Carbon $end, string $currency): array
    {
        $cash = $this->collections($start, $end, $currency);
        $days = (int) $start->diffInDays($end->copy()->startOfDay()) + 1;
        $previousEnd = $start->copy()->subDay()->endOfDay();
        $previousStart = $start->copy()->subDays($days)->startOfDay();
        $previous = $this->income($previousStart, $previousEnd, $currency);
        $income = $this->income($start, $end, $currency);

        // Fill inactive weeks so elapsed time never collapses in the chart.
        $weeks = [];
        for ($date = $start->copy()->startOfWeek(Carbon::MONDAY); $date->lte($end); $date->addWeek()) {
            $weeks[$date->toDateString()] = ['period' => $date->toDateString(), 'received' => 0, 'paid' => 0];
        }
        foreach (['received', 'paid'] as $direction) {
            foreach ($cash[$direction] as $payment) {
                $key = Carbon::parse($payment['paid_on'])->startOfWeek(Carbon::MONDAY)->toDateString();
                $weeks[$key][$direction] += (int) round($payment['amount'] * 100);
            }
        }
        $trends = array_map(fn ($week) => [
            'period' => $week['period'], 'received' => $week['received'] / 100, 'paid' => $week['paid'] / 100,
        ], array_values($weeks));

        $snapshots = [];
        $statusAsOf = $end->lt(today()) ? $end->copy()->startOfDay() : today();
        foreach (['receivables' => FinanceReceivable::class, 'payables' => FinancePayable::class] as $key => $model) {
            $aging = $this->aging($model, $currency);
            $rows = collect($aging['rows']);
            $overdue = $rows->where('days_past_due', '>', 0);
            $upcoming = $rows->filter(fn ($row) => $row['due_date'] && $row['due_date'] >= today()->toDateString()
                && $row['due_date'] <= today()->addDays(6)->toDateString());
            $snapshots[$key] = [
                'total' => $aging['total'], 'count' => $rows->count(), 'buckets' => $aging['buckets'],
                'period_status' => $this->paymentStatus($model, $currency, $start, $statusAsOf),
                'overdue_total' => round($overdue->sum('balance'), 2), 'overdue_count' => $overdue->count(),
                'oldest_days' => (int) ($rows->max('days_past_due') ?? 0),
                'due_soon_total' => round($upcoming->sum('balance'), 2), 'due_soon_count' => $upcoming->count(),
                'attention' => $overdue->sortByDesc('balance')->take(5)->values()->all(),
            ];
        }

        return [
            'received' => $cash['received_total'], 'paid' => $cash['paid_total'], 'net' => $cash['net'],
            'receipt_count' => $cash['received']->count(), 'payment_count' => $cash['paid']->count(),
            'previous' => ['start' => $previousStart->toDateString(), 'end' => $previousEnd->toDateString(),
                'received' => $previous['revenue'], 'paid' => $previous['expenses'], 'net' => $previous['net']],
            'trends' => $trends, 'expense_breakdown' => $income['expense_breakdown'],
            'as_of' => today()->toDateString(), 'status_as_of' => $statusAsOf->toDateString(), ...$snapshots,
        ];
    }

    /** Cash-basis P&L: revenue received minus expenses paid, in the window. */
    private function income(Carbon $start, Carbon $end, string $currency): array
    {
        $revenue = (float) FinancePayment::forLedger(FinanceReceivable::class, $currency)
            ->whereBetween('paid_on', [$start, $end])->sum('amount');

        $expensePayments = FinancePayment::forLedger(FinancePayable::class, $currency)
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

    /** Issued documents in the selected window, with payments and due status at the cutoff. */
    private function paymentStatus(string $model, string $currency, Carbon $start, Carbon $asOf): array
    {
        $cutoff = $asOf->toDateString();
        $entries = $model::where('currency', $currency)->whereNotIn('status', ['draft', 'void'])
            ->whereDate('issue_date', '>=', $start->toDateString())->whereDate('issue_date', '<=', $cutoff)
            ->with(['payments' => fn ($query) => $query->whereDate('paid_on', '<=', $cutoff)])->get();
        $totals = ['issued_total' => 0, 'paid_total' => 0, 'not_overdue_total' => 0, 'overdue_total' => 0];
        foreach ($entries as $entry) {
            $amount = max(0, (int) round((float) $entry->amount * 100));
            // Partition document value; excess payments cannot inflate the paid slice.
            $paid = min($amount, max(0, (int) round($entry->amount_paid * 100)));
            $totals['issued_total'] += $amount;
            $totals['paid_total'] += $paid;
            $overdue = $entry->due_date && $entry->due_date->toDateString() < $cutoff;
            $totals[$overdue ? 'overdue_total' : 'not_overdue_total'] += $amount - $paid;
        }

        return array_map(fn ($cents) => $cents / 100, $totals);
    }

    /** Live aging snapshot for a receivable/payable model. */
    private function aging(string $model, string $currency): array
    {
        $rows = $model::where('currency', $currency)->whereNotIn('status', ['draft', 'void'])->with('payments')->get()
            ->filter(fn ($r) => ! in_array($r->payment_status, ['paid', 'void'], true) && $r->balance > 0.005);

        $buckets = ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        $list = [];
        foreach ($rows as $r) {
            $buckets[$r->aging_bucket] += $r->balance;
            $list[] = [
                'id' => $r->id,
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
    private function collections(Carbon $start, Carbon $end, string $currency): array
    {
        $in = FinancePayment::forLedger(FinanceReceivable::class, $currency)
            ->whereBetween('paid_on', [$start, $end])->orderBy('paid_on')->get();
        $out = FinancePayment::forLedger(FinancePayable::class, $currency)
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
