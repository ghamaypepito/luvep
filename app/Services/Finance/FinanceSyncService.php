<?php

namespace App\Services\Finance;

use App\Models\CaseFinancePayment;
use App\Models\CaseFinancial;
use App\Models\FinancePayment;
use App\Models\FinanceReceivable;
use App\Models\InstallmentPlan;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Services\PaymentProofService;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the Finance portal's Accounts Receivable in sync with immigration case
 * money. Each immigration case gets ONE auto-maintained AR invoice (amount from
 * its CaseFinancial, or its installment-plan total), and every case payment —
 * manual receipts AND installment payments, which both write a CaseFinancePayment
 * — is mirrored into that AR's payment ledger. So finance sees the invoice,
 * client payments, aging and installment progress without re-keying.
 *
 * Best-effort + idempotent: never throws into the immigration flow, and never
 * duplicates (the AR is found by a lead-scoped marker, each mirrored payment by
 * its source CaseFinancePayment id).
 */
class FinanceSyncService
{
    /** Marks the AR row as the auto one for a case (vs a manual finance AR). */
    public const AR_MARKER = '[case-linked]';

    /** Create / update the case's AR invoice from its financials or plan. */
    public function syncForLead(Lead $lead): ?FinanceReceivable
    {
        try {
            return DB::transaction(function () use ($lead) {
                Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
                $cf = CaseFinancial::where('lead_id', $lead->id)->first();

                // Installments are optional (and the table may not be migrated on
                // every branch) — fall back to the plan total only when present.
                $plan = null;
                if (class_exists(InstallmentPlan::class) && \Illuminate\Support\Facades\Schema::hasTable('installment_plans')) {
                    $plan = InstallmentPlan::where('lead_id', $lead->id)
                        ->whereIn('status', [InstallmentPlan::STATUS_DRAFT, InstallmentPlan::STATUS_ACTIVE, InstallmentPlan::STATUS_COMPLETED])
                        ->latest('id')->first();
                }

                $amount = $cf ? (float) $cf->totalPayable() : ($plan ? (float) $plan->total_amount : 0.0);
                $ar = $this->findCaseReceivable($lead->id);
                $issued = FinanceReceivable::where('lead_id', $lead->id)->whereNotNull('invoice_document_id')
                    ->lockForUpdate()->get()->keyBy('invoice_document_id');
                $currency = strtoupper((string) ($cf?->currency ?: $plan?->currency ?: 'NZD'));
                $sameCurrency = $issued->where('currency', $currency);
                $caseNumber = $cf?->invoice_no;
                $represented = $caseNumber
                    ? $issued->first(fn ($entry) => PortalInvoiceReceivables::reference($entry->invoice_no) === PortalInvoiceReceivables::reference($caseNumber))
                    : ($sameCurrency->count() === 1 ? $sameCurrency->first() : null);
                $sameObligation = $represented && $represented->currency === $currency
                    && round($amount, 2) === (float) $represented->amount;
                // An explicit different invoice number, or a different currency,
                // can represent a separate case bill. Ambiguous same-currency
                // case totals never create a duplicate aggregate.
                $canCreateAggregate = $issued->isEmpty() || ($caseNumber ? !$represented : $sameCurrency->isEmpty());
                if (!$ar && ($amount <= 0 || !$canCreateAggregate)) {
                    $this->mirrorCasePayments($lead, $issued, $sameObligation ? $represented : null);

                    return $represented;
                }
                $ar ??= new FinanceReceivable(['lead_id' => $lead->id]);
                $invoiceNo = $ar->invoice_no ?: ($caseNumber ?: $this->nextArNo());
                if ($caseNumber && preg_match('/^AR-[0-9]+$/D', $invoiceNo)
                    && !FinanceReceivable::where('invoice_no', $caseNumber)->whereKeyNot($ar->id)->exists()) {
                    $invoiceNo = $caseNumber;
                }

                $issue = $ar->issue_date
                    ? Carbon::parse($ar->issue_date)
                    : (optional($cf?->invoice_sent_at)?->copy() ?? Carbon::today());

                $name = trim(($lead->first_name ?? '').' '.($lead->last_name ?? '')) ?: ($lead->lead_id ?? 'Client');
                $ar->fill([
                    'lead_id' => $lead->id,
                    'invoice_no' => $invoiceNo,
                    'client_name' => $name,
                    'description' => 'Immigration case'.($lead->inz_visa_type ? ' — '.$lead->inz_visa_type : ''),
                    'currency' => $currency,
                    'amount' => $amount,
                    'issue_date' => $issue->toDateString(),
                    'due_date' => $ar->due_date ? Carbon::parse($ar->due_date)->toDateString() : $issue->copy()->addDays(14)->toDateString(),
                    'status' => $ar->status === 'void' ? 'void' : 'issued',
                    'notes' => $this->withMarker($ar->notes),
                ]);
                $ar->save();
                $this->mirrorCasePayments($lead, $issued, $ar);

                return $ar;
            });
        } catch (\Throwable $e) {
            Log::warning('Finance AR sync (receivable) failed', ['lead' => $lead->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Mirror a case payment (manual or installment) onto the case's AR ledger. */
    public function mirrorPayment(CaseFinancePayment $p): void
    {
        try {
            $lead = Lead::find($p->lead_id);
            if (! $lead) {
                return;
            }

            $this->syncForLead($lead);
        } catch (\Throwable $e) {
            Log::warning('Finance AR sync (payment) failed', ['payment' => $p->id, 'error' => $e->getMessage()]);
        }
    }

    private function mirrorCasePayments(Lead $lead, Collection $issued, ?FinanceReceivable $default): void
    {
        foreach (CaseFinancePayment::where('lead_id', $lead->id)->cursor() as $payment) {
            $entry = $default;
            if ($payment->invoice_document_id && $issued->isNotEmpty()) {
                $entry = $issued->get($payment->invoice_document_id);
                if (!$entry && $default && !$default->invoice_document_id) {
                    $number = LeadDocument::whereKey($payment->invoice_document_id)->value('invoice_number');
                    if ($number && PortalInvoiceReceivables::reference($number) === PortalInvoiceReceivables::reference($default->invoice_no)) {
                        $entry = $default;
                    }
                }
            }
            $mirrors = FinancePayment::where('paymentable_type', FinanceReceivable::class)
                ->where('reference', 'like', '%(CFP-'.$payment->id.')%');
            if (!$entry) {
                if ($payment->invoice_document_id && $issued->isNotEmpty()) {
                    // A receipt explicitly allocated to a pending document no
                    // longer reduces the balance of another issued invoice.
                    $mirrors->whereIn('paymentable_id', $issued->pluck('id'))->delete();
                }
                continue;
            }
            if ($entry->invoice_document_id && $entry->status === 'void') {
                continue;
            }
            $currency = app(PaymentProofService::class)->receiptCurrency($payment);
            if ($issued->isNotEmpty() && strtoupper($currency) === strtoupper($entry->currency)) {
                // Move the tagged mirror when an existing receipt is allocated.
                $mirrors->whereHasMorph('paymentable', [FinanceReceivable::class], fn ($q) => $q->where('lead_id', $lead->id))
                    ->where('paymentable_id', '!=', $entry->id)->delete();
            }
            $this->mirrorOntoReceivable($payment, $entry, $currency);
        }
    }

    private function mirrorOntoReceivable(CaseFinancePayment $payment, FinanceReceivable $ar, string $currency): void
    {
        $tag = 'CFP-'.$payment->id;
        if (strtoupper($currency) !== strtoupper($ar->currency)) {
            // Reconciliation also removes an obsolete auto-mirror if the AR's
            // currency was corrected. Never convert or mix receipt currencies.
            $ar->payments()->where('reference', 'like', '%('.$tag.')%')->delete();

            return;
        }
        if ($ar->payments()->where('reference', 'like', '%('.$tag.')%')->exists()) {
            return;
        }
        if ($ar->invoice_document_id && filled($payment->reference) && $payment->paid_at
            && $ar->payments()->whereDate('paid_on', $payment->paid_at->toDateString())
                ->where('amount', $payment->amount)
                ->whereRaw('LOWER(TRIM(reference)) = ?', [mb_strtolower(trim($payment->reference))])->exists()) {
            return;
        }
        $ar->payments()->create([
            'amount' => $payment->amount,
            'paid_on' => optional($payment->paid_at)->toDateString() ?? Carbon::today()->toDateString(),
            'method' => $payment->method,
            'reference' => trim(($payment->reference ?: 'Payment').' ('.$tag.')'),
            'recorded_by' => $payment->recorded_by,
        ]);
    }

    /** Remove a mirrored payment when its source case payment is deleted. */
    public function removeMirroredPayment(CaseFinancePayment $p): void
    {
        try {
            FinancePayment::where('paymentable_type', (new FinanceReceivable)->getMorphClass())
                ->where('reference', 'like', '%(CFP-'.$p->id.')%')
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('Finance AR sync (payment remove) failed', ['payment' => $p->id, 'error' => $e->getMessage()]);
        }
    }

    private function findCaseReceivable(int $leadId): ?FinanceReceivable
    {
        return FinanceReceivable::where('lead_id', $leadId)
            ->where('notes', 'like', '%'.self::AR_MARKER.'%')
            ->first();
    }

    private function nextArNo(): string
    {
        $max = (int) FinanceReceivable::where('invoice_no', 'like', 'AR-%')
            ->selectRaw('MAX(CAST(SUBSTRING(invoice_no, 4) AS UNSIGNED)) as n')
            ->value('n');

        return 'AR-'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function withMarker(?string $notes): string
    {
        $notes = trim((string) $notes);

        return str_contains($notes, self::AR_MARKER) ? $notes : trim($notes.' '.self::AR_MARKER);
    }
}
