<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CaseFinancePayment;
use App\Models\FinancePayment;
use App\Models\FinanceReceivable;
use App\Models\Lead;
use App\Services\Finance\PortalInvoiceReceivables;
use App\Services\PaymentProofService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Accounts Receivable — invoices ePathways issues to clients, their payment
 * ledger, and the aging view. All figures are derived from the payment rows.
 */
class FinanceReceivableController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));

        $rows = FinanceReceivable::with(['payments', 'lead:id,inz_visa_type,is_immigration_case'])
            ->withSum(['invoicePayments as case_receipt_total' => fn ($query) => $query
                ->whereColumn('case_finance_payments.currency', 'finance_receivables.currency')], 'amount')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('client_name', 'like', "%{$q}%")
                ->orWhere('invoice_no', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%")))
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->get();

        // payment_status is derived, not a column — filter it in PHP.
        if ($status && $status !== 'all') {
            $rows = $rows->filter(fn ($r) => $r->payment_status === $status)->values();
        }

        $canReview = app(PaymentProofService::class)->canReview($request->user());
        $data = $rows->map(fn (FinanceReceivable $r) => [
            'id' => $r->id,
            'invoice_no' => $r->invoice_no,
            'client_name' => $r->client_name,
            'description' => $r->description,
            'currency' => $r->currency,
            'amount' => (float) $r->amount,
            'amount_paid' => $r->amount_paid,
            'balance' => $r->balance,
            'issue_date' => optional($r->issue_date)->toDateString(),
            'due_date' => optional($r->due_date)->toDateString(),
            'status' => $r->status,
            'payment_status' => $r->payment_status,
            'portal_issued' => $r->invoice_document_id !== null,
            'student_visa_invoice' => $r->isStudentVisaInvoice(),
            'can_manage_student_visa_invoice' => $canReview && $r->isStudentVisaInvoice(),
            'can_flag_internal_settlement' => $r->canFlagInternalSettlement((float) $r->case_receipt_total),
            'internal_settlement_pending' => $canReview && $r->internal_settlement_pending,
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
                'case_receipt' => preg_match('/\(CFP-\d+\)$/D', (string) $p->reference) === 1,
            ])->values(),
        ])->values();

        $unposted = app(PortalInvoiceReceivables::class)->unposted($q, $status ?: 'all');

        return inertia('portal/finance/Receivables', [
            'rows' => $data->concat($unposted)->sortByDesc('issue_date')->values(),
            'summary' => array_replace($this->summary($rows), ['count' => $data->count() + $unposted->count()]),
            'filters' => ['status' => $status ?: 'all', 'q' => $q],
            'currencyDefault' => strtoupper(config('services.booking.currency', 'nzd')),
        ]);
    }

    /** Outstanding total, overdue total, aging buckets, all-time collected. */
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
            'collected' => round((float) FinancePayment::forLedger(FinanceReceivable::class, $currency)->sum('amount'), 2),
            'buckets' => array_map(fn ($v) => round($v, 2), $buckets),
            'count' => $rows->count(),
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'invoice_no' => 'nullable|string|max:60|unique:finance_receivables,invoice_no',
            'client_name' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'currency' => 'nullable|string|size:3',
            'amount' => 'required|numeric|min:0|max:99999999',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'status' => 'nullable|in:draft,sent',
            'notes' => 'nullable|string|max:2000',
        ]);

        $next = (FinanceReceivable::max('id') ?? 0) + 1;

        $r = FinanceReceivable::create([
            'invoice_no' => $data['invoice_no'] ?: 'AR-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT),
            'client_name' => $data['client_name'],
            'description' => $data['description'] ?? null,
            'currency' => strtoupper($data['currency'] ?? config('services.booking.currency', 'nzd')),
            'amount' => $data['amount'],
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'],
            'status' => $data['status'] ?? 'draft',
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', "Invoice {$r->invoice_no} created.");
    }

    public function update(Request $request, FinanceReceivable $receivable)
    {
        $data = $request->validate([
            'client_name' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'currency' => 'nullable|string|size:3',
            'amount' => 'required|numeric|min:0|max:99999999',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'status' => 'nullable|in:draft,sent,paid,void',
            'notes' => 'nullable|string|max:2000',
        ]);

        $receivable->update([
            'client_name' => $data['client_name'],
            'description' => $data['description'] ?? null,
            'currency' => $receivable->invoice_document_id ? $receivable->currency : strtoupper($data['currency'] ?? $receivable->currency),
            'amount' => $receivable->invoice_document_id ? $receivable->amount : $data['amount'],
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'],
            'status' => $data['status'] ?? $receivable->status,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', "Invoice {$receivable->invoice_no} updated.");
    }

    public function destroy(FinanceReceivable $receivable)
    {
        abort_if($receivable->invoice_document_id, 422, 'Void the issued portal invoice to retain its Finance history.');

        $receivable->payments()->delete();
        $receivable->delete();

        return back()->with('success', 'Invoice deleted.');
    }

    public function recordPayment(Request $request, FinanceReceivable $receivable)
    {
        DB::transaction(function () use ($receivable, $request) {
            if ($receivable->invoice_document_id) {
                $lead = Lead::whereKey($receivable->lead_id)->lockForUpdate()->first();
                $receivable = FinanceReceivable::whereKey($receivable->id)->lockForUpdate()->firstOrFail();
                $receivable->setRelation('lead', $lead);
            }
            $studentVisa = $receivable->isStudentVisaInvoice();
            if ($studentVisa) {
                abort_unless(app(PaymentProofService::class)->canReview($request->user()), 403);
            }
            $data = $request->validate([
                'amount' => $studentVisa ? 'required|numeric|decimal:0,2|min:0.01|max:99999999' : 'required|numeric|min:0.01|max:99999999',
                'paid_on' => $studentVisa ? 'required|date|before_or_equal:today' : 'required|date',
                'method' => $studentVisa ? 'nullable|string|max:40' : 'nullable|string|max:60',
                'reference' => $studentVisa ? ['required', 'string', 'max:120', 'not_regex:/\(CFP-\d+\)/i'] : 'nullable|string|max:120',
                'notes' => 'nullable|string|max:500',
            ]);
            if ($receivable->invoice_document_id && filled($data['reference'] ?? null) && $receivable->payments()
                ->whereDate('paid_on', $data['paid_on'])->where('amount', $data['amount'])
                ->get(['reference'])->contains(fn ($payment) =>
                    strcasecmp(trim((string) preg_replace('/\s*\(CFP-\d+\)$/D', '', $payment->reference ?? '')), trim($data['reference'])) === 0)) {
                throw ValidationException::withMessages(['reference' => 'This dated payment is already in the ledger.']);
            }
            if ($studentVisa) {
                $this->recordStudentVisaReceipt($receivable, $data);
            } else {
                $receivable->payments()->create($data + ['recorded_by' => auth()->id()]);
            }

            // A payment implies the invoice was issued; settle it when fully paid.
            $receivable->refresh();
            if ($receivable->status !== 'void' && $receivable->balance <= 0.005) {
                $receivable->update(['status' => 'paid']);
            } elseif ($receivable->status === 'draft') {
                $receivable->update(['status' => 'sent']);
            }
        });

        return back()->with('success', 'Payment recorded.');
    }

    private function recordStudentVisaReceipt(FinanceReceivable $receivable, array $data): void
    {
        if ($receivable->status === 'void') {
            throw ValidationException::withMessages(['amount' => 'Record payments against an active invoice.']);
        }

        PaymentProofService::assertReferenceAvailable($receivable->lead_id, $data['reference']);
        $receipt = $receivable->invoicePayments()->create([
            'lead_id' => $receivable->lead_id,
            'currency' => $receivable->currency,
            'amount' => $data['amount'],
            'paid_at' => $data['paid_on'],
            'method' => $data['method'] ?? null,
            'reference' => trim($data['reference']),
            'recorded_by' => auth()->id(),
            'finance_verified' => true,
        ]);
        $mirror = $receivable->payments()->where('reference', 'like', '%(CFP-'.$receipt->id.')')->first();
        if (! $mirror) {
            throw ValidationException::withMessages(['amount' => 'Finance could not link this receipt to the invoice ledger. No payment was recorded.']);
        }
        $mirror->update(['notes' => $data['notes'] ?? null]);

        ActivityLog::record('finance.invoice_payment_recorded', [
            'portal' => 'finance', 'entity_type' => FinanceReceivable::class, 'entity_id' => $receivable->id,
            'description' => 'Recorded a verified Student Visa invoice receipt',
            'properties' => ['invoice_document_id' => $receivable->invoice_document_id, 'case_finance_payment_id' => $receipt->id],
        ]);
    }

    public function updateInternalSettlement(Request $request, FinanceReceivable $receivable)
    {
        abort_unless(app(PaymentProofService::class)->canReview($request->user()), 403);
        $request->validate(['pending' => 'required|boolean']);
        $pending = $request->boolean('pending');

        DB::transaction(function () use ($receivable, $pending) {
            $lead = Lead::whereKey($receivable->lead_id)->lockForUpdate()->first();
            $receivable = FinanceReceivable::whereKey($receivable->id)->lockForUpdate()->firstOrFail();
            $receivable->setRelation('lead', $lead);
            abort_unless($receivable->isStudentVisaInvoice(), 404);
            if ($receivable->internal_settlement_pending === $pending) {
                return;
            }
            if ($pending && ! $receivable->canFlagInternalSettlement((float) $receivable->invoicePayments()
                ->where('currency', $receivable->currency)->sum('amount'))) {
                throw ValidationException::withMessages(['pending' => 'Record the full client payment on the active invoice before flagging internal settlement.']);
            }

            $receivable->internal_settlement_pending = $pending;
            $receivable->save();
            ActivityLog::record('finance.invoice_internal_settlement_changed', [
                'portal' => 'finance', 'entity_type' => FinanceReceivable::class, 'entity_id' => $receivable->id,
                'description' => $pending ? 'Flagged invoice internal settlement as pending' : 'Cleared invoice internal settlement flag',
                'properties' => ['invoice_document_id' => $receivable->invoice_document_id, 'pending' => $pending],
                'changes' => ['internal_settlement_pending' => ['old' => ! $pending, 'new' => $pending]],
            ]);
        });

        return back()->with('success', $pending ? 'Internal settlement flagged as pending.' : 'Internal settlement flag cleared.');
    }

    public function deletePayment(FinanceReceivable $receivable, FinancePayment $payment)
    {
        abort_unless(
            $payment->paymentable_id === $receivable->id && $payment->paymentable_type === FinanceReceivable::class,
            404
        );

        $matchedCaseReceipt = $receivable->invoice_document_id && filled($payment->reference)
            && CaseFinancePayment::where('invoice_document_id', $receivable->invoice_document_id)
                ->whereDate('paid_at', $payment->paid_on->toDateString())->where('amount', $payment->amount)
                ->whereRaw('LOWER(TRIM(reference)) = ?', [mb_strtolower(trim($payment->reference))])->exists();
        abort_if($matchedCaseReceipt || preg_match('/\(CFP-\d+\)$/D', (string) $payment->reference),
            422, 'Manage this payment from its original case receipt.');
        $payment->delete();

        $receivable->refresh();
        if ($receivable->status === 'paid' && $receivable->balance > 0.005) {
            $receivable->update(['status' => 'sent']);
        }

        return back()->with('success', 'Payment removed.');
    }
}
