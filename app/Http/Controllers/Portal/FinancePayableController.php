<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\FinancePayable;
use App\Models\FinancePayment;
use Illuminate\Http\Request;

/**
 * Accounts Payable — bills ePathways owes vendors, their payment ledger, and the
 * aging view. Mirrors the receivables controller; figures are ledger-derived.
 */
class FinancePayableController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));

        $rows = FinancePayable::with('payments')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('vendor_name', 'like', "%{$q}%")
                ->orWhere('bill_no', 'like', "%{$q}%")
                ->orWhere('category', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%")))
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->get();

        if ($status && $status !== 'all') {
            $rows = $rows->filter(fn ($r) => $r->payment_status === $status)->values();
        }

        $data = $rows->map(fn (FinancePayable $r) => [
            'id' => $r->id,
            'bill_no' => $r->bill_no,
            'vendor_name' => $r->vendor_name,
            'category' => $r->category,
            'description' => $r->description,
            'currency' => $r->currency,
            'amount' => (float) $r->amount,
            'amount_paid' => $r->amount_paid,
            'balance' => $r->balance,
            'issue_date' => optional($r->issue_date)->toDateString(),
            'due_date' => optional($r->due_date)->toDateString(),
            'status' => $r->status,
            'payment_status' => $r->payment_status,
            'days_past_due' => $r->days_past_due,
            'aging_bucket' => $r->aging_bucket,
            'notes' => $r->notes,
            'payments' => $r->payments->map(fn ($p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'paid_on' => optional($p->paid_on)->toDateString(),
                'method' => $p->method,
                'reference' => $p->reference,
                'notes' => $p->notes,
            ])->values(),
        ])->values();

        return inertia('portal/finance/Payables', [
            'rows' => $data,
            'summary' => $this->summary($rows),
            'filters' => ['status' => $status ?: 'all', 'q' => $q],
            'currencyDefault' => strtoupper(config('services.booking.currency', 'nzd')),
        ]);
    }

    private function summary($rows): array
    {
        $buckets = ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        $outstanding = 0;
        $overdue = 0;
        $currency = strtoupper(config('services.booking.currency', 'nzd'));

        foreach ($rows->where('currency', $currency) as $r) {
            if (in_array($r->payment_status, ['paid', 'void'], true) || $r->balance <= 0.005) {
                continue;
            }
            $buckets[$r->aging_bucket] += $r->balance;
            $outstanding += $r->balance;
            if ($r->days_past_due > 0) {
                $overdue += $r->balance;
            }
        }

        return [
            'outstanding' => round($outstanding, 2),
            'overdue' => round($overdue, 2),
            'paid' => round((float) FinancePayment::forLedger(FinancePayable::class, $currency)->sum('amount'), 2),
            'buckets' => array_map(fn ($v) => round($v, 2), $buckets),
            'count' => $rows->count(),
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'bill_no' => 'nullable|string|max:60|unique:finance_payables,bill_no',
            'vendor_name' => 'required|string|max:200',
            'category' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:2000',
            'currency' => 'nullable|string|size:3',
            'amount' => 'required|numeric|min:0|max:99999999',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'status' => 'nullable|in:draft,approved',
            'notes' => 'nullable|string|max:2000',
        ]);

        $next = (FinancePayable::max('id') ?? 0) + 1;

        $r = FinancePayable::create([
            'bill_no' => $data['bill_no'] ?: 'AP-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT),
            'vendor_name' => $data['vendor_name'],
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'currency' => strtoupper($data['currency'] ?? config('services.booking.currency', 'nzd')),
            'amount' => $data['amount'],
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'],
            'status' => $data['status'] ?? 'draft',
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', "Bill {$r->bill_no} created.");
    }

    public function update(Request $request, FinancePayable $payable)
    {
        $data = $request->validate([
            'vendor_name' => 'required|string|max:200',
            'category' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:2000',
            'currency' => 'nullable|string|size:3',
            'amount' => 'required|numeric|min:0|max:99999999',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'status' => 'nullable|in:draft,approved,paid,void',
            'notes' => 'nullable|string|max:2000',
        ]);

        $payable->update([
            'vendor_name' => $data['vendor_name'],
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'currency' => strtoupper($data['currency'] ?? $payable->currency),
            'amount' => $data['amount'],
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'],
            'status' => $data['status'] ?? $payable->status,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', "Bill {$payable->bill_no} updated.");
    }

    public function destroy(FinancePayable $payable)
    {
        $payable->payments()->delete();
        $payable->delete();

        return back()->with('success', 'Bill deleted.');
    }

    public function recordPayment(Request $request, FinancePayable $payable)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:99999999',
            'paid_on' => 'required|date',
            'method' => 'nullable|string|max:60',
            'reference' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:500',
        ]);

        $payable->payments()->create([
            'amount' => $data['amount'],
            'paid_on' => $data['paid_on'],
            'method' => $data['method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'recorded_by' => auth()->id(),
        ]);

        $payable->refresh();
        if ($payable->status !== 'void' && $payable->balance <= 0.005) {
            $payable->update(['status' => 'paid']);
        } elseif ($payable->status === 'draft') {
            $payable->update(['status' => 'approved']);
        }

        return back()->with('success', 'Payment recorded.');
    }

    public function deletePayment(FinancePayable $payable, FinancePayment $payment)
    {
        abort_unless(
            $payment->paymentable_id === $payable->id && $payment->paymentable_type === FinancePayable::class,
            404
        );

        $payment->delete();

        $payable->refresh();
        if ($payable->status === 'paid' && $payable->balance > 0.005) {
            $payable->update(['status' => 'approved']);
        }

        return back()->with('success', 'Payment removed.');
    }
}
