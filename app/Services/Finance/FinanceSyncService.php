<?php

namespace App\Services\Finance;

use App\Models\CaseFinancePayment;
use App\Models\CaseFinancial;
use App\Models\FinancePayment;
use App\Models\FinanceReceivable;
use App\Models\InstallmentPlan;
use App\Models\Lead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
    private const AR_MARKER = '[case-linked]';

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
                if ($amount <= 0 && ! $ar) {
                    return null; // nothing invoiced yet
                }
                $ar ??= new FinanceReceivable(['lead_id' => $lead->id]);

                $issue = $ar->issue_date
                    ? Carbon::parse($ar->issue_date)
                    : (optional($cf?->invoice_sent_at)?->copy() ?? Carbon::today());

                $name = trim(($lead->first_name ?? '').' '.($lead->last_name ?? '')) ?: ($lead->lead_id ?? 'Client');
                $ar->fill([
                    'lead_id' => $lead->id,
                    'invoice_no' => $ar->invoice_no ?: ($cf?->invoice_no ?: $this->nextArNo()),
                    'client_name' => $name,
                    'description' => 'Immigration case'.($lead->inz_visa_type ? ' — '.$lead->inz_visa_type : ''),
                    'currency' => strtoupper((string) ($cf?->currency ?: 'NZD')),
                    'amount' => $amount,
                    'issue_date' => $issue->toDateString(),
                    'due_date' => $ar->due_date ? Carbon::parse($ar->due_date)->toDateString() : $issue->copy()->addDays(14)->toDateString(),
                    'status' => $ar->status === 'void' ? 'void' : 'issued',
                    'notes' => $this->withMarker($ar->notes),
                ]);
                $ar->save();
                foreach (CaseFinancePayment::where('lead_id', $lead->id)->cursor() as $payment) {
                    $this->mirrorOntoReceivable($payment, $ar);
                }

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

    private function mirrorOntoReceivable(CaseFinancePayment $payment, FinanceReceivable $ar): void
    {
        $tag = 'CFP-'.$payment->id;
        if ($ar->payments()->where('reference', 'like', '%('.$tag.')%')->exists()) {
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
