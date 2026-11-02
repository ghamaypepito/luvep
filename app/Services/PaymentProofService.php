<?php

namespace App\Services;

use App\Jobs\SendLeadFollowupEmail;
use App\Models\ActivityLog;
use App\Models\CaseFinancePayment;
use App\Models\CaseFinancial;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\PaymentProofReview;
use App\Models\User;
use App\Support\LeadAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentProofService
{
    public function canReview(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['admin', 'super_admin', 'finance'], true);
    }

    public function canRead(User $user, Lead $lead): bool
    {
        return $user->is_active && ($this->canReview($user) || LeadAccess::canView($user, $lead));
    }

    public static function isInvoice(LeadDocument $document): bool
    {
        return $document->source_variant === 'invoice' || str_starts_with((string) $document->source_variant, 'invoice:');
    }

    public static function invoiceQuery(Lead $lead)
    {
        return $lead->documents()->where('status', '!=', 'Rejected')->where(fn ($query) => $query->where('source_variant', 'invoice')->orWhere('source_variant', 'like', 'invoice:%'));
    }

    public function currency(LeadDocument $invoice): string
    {
        return strtoupper($invoice->notes['engagement_currency'] ?? 'NZD');
    }

    public function receiptCurrency(CaseFinancePayment $payment): string
    {
        return $payment->currency ?: (CaseFinancial::where('lead_id', $payment->lead_id)->value('currency') ?: 'NZD');
    }

    private function cents($value): int
    {
        return (int) round((float) $value * 100);
    }

    public function invoiceSummary(LeadDocument $invoice): array
    {
        $currency = $this->currency($invoice);
        $payments = $invoice->relationLoaded('invoicePayments') ? $invoice->invoicePayments : $invoice->invoicePayments()->get();
        $confirmed = $payments->filter(fn ($payment) => $this->receiptCurrency($payment) === $currency)->sum(fn ($payment) => $this->cents($payment->amount));
        $total = $invoice->invoice_total !== null ? $this->cents($invoice->invoice_total) : null;
        $legacyPaid = $invoice->status === LeadDocument::STATUS_APPROVED && $confirmed === 0;
        $paid = $invoice->status === LeadDocument::STATUS_APPROVED || ($total !== null && $total >= 0 && $confirmed >= $total);

        return ['total' => $total !== null ? (float) ($total / 100) : null, 'confirmed' => $legacyPaid ? null : (float) ($confirmed / 100),
            'remaining' => $paid ? 0.0 : ($total !== null ? (float) (max(0, $total - $confirmed) / 100) : null),
            'currency' => $currency, 'paid' => $paid, 'legacy_paid' => $legacyPaid];
    }

    public function metadata(LeadDocument $proof): ?array
    {
        $review = $proof->proofReview;
        if (! $review) {
            return null;
        }
        $invoice = $review->invoice;
        $payment = $review->payment;

        return ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number ?: $invoice->original_name,
            'currency' => $this->currency($invoice), 'finance_payment_id' => $payment?->id,
            'amount' => $payment ? (float) $payment->amount : null, 'paid_at' => $payment?->paid_at?->toDateString(),
            'reference' => $payment?->reference, 'supporting' => $review->supporting,
            'summary' => $this->invoiceSummary($invoice)];
    }

    public function reviewOptions(LeadDocument $proof): array
    {
        $pack = $proof->notes['engagement_pack_id'] ?? null;
        $invoices = self::invoiceQuery($proof->lead)
            ->when($pack === null, fn ($query) => $query->whereNull('notes->engagement_pack_id'), fn ($query) => $query->where('notes->engagement_pack_id', $pack))
            ->with('invoicePayments')->latest('id')->get();

        return ['invoices' => $invoices->map(fn ($invoice) => ['id' => $invoice->id,
            'number' => $invoice->invoice_number ?: $invoice->original_name, 'currency_recorded' => ! empty($invoice->notes['engagement_currency']), 'summary' => $this->invoiceSummary($invoice)])->values(),
            'payments' => CaseFinancePayment::where('lead_id', $proof->lead_id)->orderByDesc('paid_at')->get()->map(fn ($payment) => [
                'id' => $payment->id, 'invoice_id' => $payment->invoice_document_id, 'amount' => (float) $payment->amount,
                'currency' => $this->receiptCurrency($payment), 'paid_at' => $payment->paid_at?->toDateString(), 'reference' => $payment->reference,
            ]), 'review' => $this->metadata($proof), 'status' => $proof->status,
            'note' => $proof->note, 'reviewed_by' => $proof->reviewer?->name, 'reviewed_at' => $proof->reviewed_at?->toIso8601String()];
    }

    public static function assertReferenceAvailable(int $leadId, string $reference): void
    {
        if (trim($reference) !== '' && CaseFinancePayment::where('lead_id', $leadId)->whereRaw('LOWER(TRIM(reference)) = ?', [mb_strtolower(trim($reference))])->exists()) {
            throw ValidationException::withMessages(['reference' => 'This transfer reference is already recorded. Link the proof to the existing payment instead.']);
        }
    }

    public static function assertEvidenceCanBeDeleted(LeadDocument $document): void
    {
        if ($document->source_variant === 'proof_of_payment'
            || PaymentProofReview::where('invoice_document_id', $document->id)->exists()
            || $document->invoicePayments()->exists()) {
            throw ValidationException::withMessages(['document' => 'Payment evidence and invoices with recorded payments are retained for reconciliation. Ask Finance to review a correction.']);
        }
    }

    public function review(User $user, LeadDocument $document, array $input): bool
    {
        abort_unless($this->canReview($user), 403);
        $data = Validator::make($input, ['status' => ['required', Rule::in(['Submitted', 'UnderReview', 'Approved', 'Rejected'])],
            'note' => ['nullable', 'string', 'max:500']])->validate();

        return DB::transaction(function () use ($user, $document, $input, $data) {
            $lead = Lead::whereKey($document->lead_id)->lockForUpdate()->firstOrFail();
            $proof = LeadDocument::where('lead_id', $lead->id)->whereKey($document->id)->lockForUpdate()->firstOrFail();
            abort_unless($proof->source_variant === 'proof_of_payment', 404);
            if ($proof->status === 'Approved') {
                if ($data['status'] === 'Approved') {
                    return false;
                }
                throw ValidationException::withMessages(['status' => 'Confirmed payment evidence cannot be changed through this action. Ask Finance to review a correction.']);
            }
            if ($proof->status === $data['status'] && ($data['note'] ?? $proof->note) === $proof->note) {
                return false;
            }

            $invoice = null;
            $newlySettled = false;
            if ($data['status'] === 'Approved') {
                $paymentData = Validator::make($input, [
                    'invoice_id' => ['required', 'integer'], 'payment_mode' => ['required', Rule::in(['new', 'existing'])],
                    'amount' => ['required_if:payment_mode,new', 'nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999.99'],
                    'paid_at' => ['required_if:payment_mode,new', 'nullable', 'date', 'before_or_equal:today'],
                    'reference' => ['required_if:payment_mode,new', 'nullable', 'string', 'max:120'],
                    'finance_payment_id' => ['required_if:payment_mode,existing', 'nullable', 'integer'],
                ])->validate();
                $invoice = self::invoiceQuery($lead)->whereKey($paymentData['invoice_id'])->lockForUpdate()->first();
                $pack = $proof->notes['engagement_pack_id'] ?? null;
                if (! $invoice || ($invoice->notes['engagement_pack_id'] ?? null) !== $pack) {
                    throw ValidationException::withMessages(['invoice_id' => 'Choose an invoice from this client and engagement pack.']);
                }
                $review = PaymentProofReview::where('document_id', $proof->id)->first();
                if ($review && $review->invoice_document_id !== $invoice->id) {
                    throw ValidationException::withMessages(['invoice_id' => 'This proof was uploaded for a different invoice.']);
                }
                if ($invoice->invoice_total === null || (float) $invoice->invoice_total <= 0) {
                    throw ValidationException::withMessages(['invoice_id' => 'Record a positive invoice total before verifying a payment.']);
                }
                if (empty($invoice->notes['engagement_currency'])) {
                    $legacyCurrency = Validator::make($input, ['invoice_currency' => ['required', Rule::in(['NZD', 'USD'])]])->validate()['invoice_currency'];
                    $invoice->update(['notes' => array_merge($invoice->notes ?? [], ['engagement_currency' => $legacyCurrency])]);
                }
                if (! empty($input['invoice_currency']) && $input['invoice_currency'] !== $this->currency($invoice)) {
                    throw ValidationException::withMessages(['invoice_currency' => 'The invoice currency was updated. Reopen the proof and check the invoice before confirming.']);
                }
                $wasPaid = $this->invoiceSummary($invoice)['paid'];
                $currency = $this->currency($invoice);
                if ($paymentData['payment_mode'] === 'existing') {
                    $payment = CaseFinancePayment::where('lead_id', $lead->id)->whereKey($paymentData['finance_payment_id'])->lockForUpdate()->first();
                    if (! $payment || $this->cents($payment->amount) <= 0 || ($payment->invoice_document_id !== null && $payment->invoice_document_id !== $invoice->id) || $this->receiptCurrency($payment) !== $currency) {
                        throw ValidationException::withMessages(['finance_payment_id' => 'Choose a payment for this client, invoice and currency.']);
                    }
                    if ($payment->invoice_document_id === null) {
                        $payment->update(['invoice_document_id' => $invoice->id, 'currency' => $currency]);
                    }
                } else {
                    $reference = trim($paymentData['reference']);
                    if ($reference === '') {
                        throw ValidationException::withMessages(['reference' => 'Enter the transfer reference.']);
                    }
                    self::assertReferenceAvailable($lead->id, $reference);
                    $payment = CaseFinancePayment::create(['lead_id' => $lead->id, 'invoice_document_id' => $invoice->id,
                        'currency' => $currency, 'amount' => $paymentData['amount'], 'paid_at' => $paymentData['paid_at'],
                        'reference' => $reference, 'method' => 'bank_transfer', 'recorded_by' => $user->id]);
                }
                PaymentProofReview::updateOrCreate(['document_id' => $proof->id], [
                    'invoice_document_id' => $invoice->id, 'finance_payment_id' => $payment->id, 'supporting' => $paymentData['payment_mode'] === 'existing']);
                $invoice->unsetRelation('invoicePayments');
                $summary = $this->invoiceSummary($invoice);
                $newlySettled = ! $wasPaid && $summary['paid'];
                if ($newlySettled) {
                    $invoice->update(['status' => 'Approved', 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
                }
            } elseif ($data['status'] === 'Rejected') {
                Validator::make($input, ['note' => ['required', 'string', 'max:500']])->validate();
            }

            $proof->update(['status' => $data['status'], 'note' => $data['status'] === 'Approved' ? null : ($data['note'] ?? $proof->note),
                'reviewed_by' => $user->id, 'reviewed_at' => now()]);
            $lead->recordStaffActivity($data['status'].' payment proof '.$proof->original_name);
            ActivityLog::record('payment_proof.reviewed', ['description' => 'Payment proof '.$data['status'], 'properties' => [
                'subject_type' => 'Lead', 'subject_id' => $lead->id, 'document_id' => $proof->id,
                'status' => $data['status'], 'note' => $proof->note, 'invoice_id' => $invoice?->id,
                'finance_payment_id' => $payment->id ?? null, 'amount' => isset($payment) ? $payment->amount : null,
                'currency' => isset($payment) ? $this->receiptCurrency($payment) : null,
            ]]);

            if ($newlySettled) {
                // A separate invoice can remain outstanding on the same case.
                $outstanding = self::invoiceQuery($lead)->with('invoicePayments')->get()->contains(fn ($item) => ! $this->invoiceSummary($item)['paid']);
                $advanced = false;
                if (! $outstanding) {
                    $advanced = Lead::withoutEvents(fn () => $lead->advanceImmigrationStage('Invoice Paid',
                        ['For Assessment', 'Endorsed', 'Agreement Sent', 'Agreement Signed', 'For Agreement & Invoice', 'Request for Information'], $user->id));
                }
                DB::afterCommit(function () use ($lead, $invoice, $advanced) {
                    try {
                        $sent = app(EmailAutomationService::class)->fire('immigration.invoice.paid', $lead, [
                            'invoice_number' => $invoice->invoice_number ?? '', 'invoice_total' => $invoice->invoice_total,
                            'currency' => $this->currency($invoice),
                        ]);
                        if (! $sent && $advanced) {
                            SendLeadFollowupEmail::sendKey('invoice_paid', $lead);
                        }
                    } catch (\Throwable $exception) {
                        Log::warning('Payment settlement notification failed', ['lead_id' => $lead->id, 'invoice_id' => $invoice->id, 'error' => $exception->getMessage()]);
                    }
                });
            }

            return true;
        });
    }
}
