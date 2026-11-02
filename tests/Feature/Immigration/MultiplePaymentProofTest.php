<?php

namespace Tests\Feature\Immigration;

use App\Models\ActivityLog;
use App\Models\CaseFinancePayment;
use App\Models\CaseFinancial;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\PaymentProofReview;
use App\Models\User;
use App\Services\EmailAutomationService;
use App\Services\PaymentProofService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MultiplePaymentProofTest extends TestCase
{
    use RefreshDatabase;

    private Lead $lead;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->finance = User::factory()->create(['role' => 'finance']);
        $this->actingAs($this->finance);
        $this->lead = Lead::create([
            'first_name' => 'Synthetic', 'last_name' => 'Payment', 'email' => 'payments@example.test',
            'is_immigration_case' => true, 'immigration_stage' => 'For Agreement & Invoice',
        ]);
        $this->lead->forceFill(['engagement_signing_token' => 'synthetic-payment-token'])->saveQuietly();
    }

    private function invoice(float $total = 1200, string $currency = 'NZD', ?Lead $lead = null, ?string $pack = null): LeadDocument
    {
        $lead ??= $this->lead;
        $notes = ['engagement_currency' => $currency];
        if ($pack) {
            $notes['engagement_pack_id'] = $pack;
        }

        return LeadDocument::create([
            'lead_id' => $lead->id, 'original_name' => 'invoice.pdf',
            'file_path' => 'synthetic/invoice-'.uniqid().'.pdf', 'mime' => 'application/pdf',
            'size' => 8, 'source' => 'generated', 'source_variant' => 'invoice',
            'status' => 'StaffShared', 'invoice_total' => $total, 'invoice_number' => 'INV-'.uniqid(), 'notes' => $notes,
        ]);
    }

    private function proof(?Lead $lead = null, ?string $pack = null): LeadDocument
    {
        $lead ??= $this->lead;
        $path = 'synthetic/proof-'.uniqid().'.pdf';
        Storage::disk('local')->put($path, '%PDF synthetic');

        return LeadDocument::create([
            'lead_id' => $lead->id, 'original_name' => basename($path), 'file_path' => $path,
            'mime' => 'application/pdf', 'size' => 14, 'source' => 'upload',
            'source_variant' => 'proof_of_payment', 'status' => 'Submitted',
            'notes' => $pack ? ['engagement_pack_id' => $pack] : null,
        ]);
    }

    private function confirm(LeadDocument $proof, LeadDocument $invoice, string $reference, float $amount = 400, array $extra = [])
    {
        return $this->postJson("/admin/leads/{$proof->lead_id}/documents/{$proof->id}/status", array_merge([
            'status' => 'Approved', 'invoice_id' => $invoice->id, 'payment_mode' => 'new',
            'amount' => $amount, 'paid_at' => '2026-11-02', 'reference' => $reference,
        ], $extra));
    }

    public function test_partial_payment_does_not_settle_invoice_or_advance_case(): void
    {
        $invoice = $this->invoice();
        $proof = $this->proof();
        $this->confirm($proof, $invoice, 'PART-001')->assertRedirect();
        $this->assertSame('StaffShared', $invoice->fresh()->status);
        $this->assertSame('For Agreement & Invoice', $this->lead->fresh()->immigration_stage);
        $this->assertSame('Approved', $proof->fresh()->status);
        $this->assertDatabaseHas('case_finance_payments', ['lead_id' => $this->lead->id, 'amount' => 400, 'invoice_document_id' => $invoice->id, 'currency' => 'NZD']);
        $summary = app(PaymentProofService::class)->invoiceSummary($invoice->fresh());
        $this->assertSame(400.0, $summary['confirmed']);
        $this->assertSame(800.0, $summary['remaining']);
        $this->assertFalse($summary['paid']);
        Mail::assertNothingOutgoing();
    }

    public function test_full_settlement_marks_only_its_invoice_and_automation_fires_once(): void
    {
        $invoice = $this->invoice();
        $other = $this->invoice(250);
        $automation = $this->mock(EmailAutomationService::class);
        $automation->shouldReceive('fire')->once()->with('immigration.invoice.paid', \Mockery::type(Lead::class), \Mockery::on(fn ($context) => $context['invoice_number'] === $invoice->invoice_number))->andReturn(false);
        $first = $this->proof();
        $second = $this->proof();
        $this->confirm($first, $invoice, 'FULL-1', 400)->assertRedirect();
        $this->confirm($second, $invoice, 'FULL-2', 800)->assertRedirect();
        $this->confirm($second, $invoice, 'FULL-2', 800)->assertRedirect();
        $this->assertSame('Approved', $invoice->fresh()->status);
        $this->assertSame('StaffShared', $other->fresh()->status);
        $this->assertSame('For Agreement & Invoice', $this->lead->fresh()->immigration_stage);
        $this->assertDatabaseCount('case_finance_payments', 2);
        $this->assertSame(0.0, app(PaymentProofService::class)->invoiceSummary($invoice->fresh())['remaining']);
    }

    public function test_supporting_files_reuse_an_existing_receipt_without_double_counting(): void
    {
        $invoice = $this->invoice();
        $first = $this->proof();
        $this->confirm($first, $invoice, 'SAME-TRANSFER')->assertRedirect();
        $payment = CaseFinancePayment::sole();
        $second = $this->proof();
        $this->confirm($second, $invoice, 'ignored', 400, ['payment_mode' => 'existing', 'finance_payment_id' => $payment->id])->assertRedirect();
        $this->assertDatabaseCount('case_finance_payments', 1);
        $this->assertDatabaseCount('payment_proof_reviews', 2);
        $this->assertSame(400.0, app(PaymentProofService::class)->invoiceSummary($invoice->fresh())['confirmed']);
        $this->assertTrue(PaymentProofReview::where('document_id', $second->id)->sole()->supporting);
    }

    public function test_previously_manual_receipt_can_be_allocated_once(): void
    {
        $invoice = $this->invoice();
        $payment = CaseFinancePayment::create(['lead_id' => $this->lead->id, 'amount' => 400, 'paid_at' => '2026-11-01', 'reference' => 'MANUAL', 'recorded_by' => $this->finance->id]);
        $proof = $this->proof();
        $this->confirm($proof, $invoice, 'ignored', 400, ['payment_mode' => 'existing', 'finance_payment_id' => $payment->id])->assertRedirect();
        $this->assertSame($invoice->id, $payment->fresh()->invoice_document_id);
        $this->assertDatabaseCount('case_finance_payments', 1);
    }

    public function test_duplicate_reference_is_rejected_and_replay_creates_no_extra_effects(): void
    {
        $invoice = $this->invoice();
        $proof = $this->proof();
        $this->confirm($proof, $invoice, ' Ref-ABC ')->assertRedirect();
        $before = $proof->fresh()->getAttributes();
        $this->confirm($proof, $invoice, 'Ref-ABC')->assertRedirect();
        $this->assertSame($before, $proof->fresh()->getAttributes());
        $this->confirm($this->proof(), $invoice, 'ref-abc')->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->assertDatabaseCount('case_finance_payments', 1);
        $this->assertSame(1, ActivityLog::where('action', 'payment_proof.reviewed')->count());
    }

    public function test_cross_case_cross_pack_and_cross_invoice_receipt_links_are_rejected(): void
    {
        $invoice = $this->invoice();
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Case', 'is_immigration_case' => true]);
        $this->confirm($this->proof(), $this->invoice(500, 'NZD', $other), 'WRONG')->assertUnprocessable();
        $this->confirm($this->proof(null, 'pack-A'), $this->invoice(500, 'NZD', null, 'pack-B'), 'WRONG-PACK')->assertUnprocessable();
        $payment = CaseFinancePayment::create(['lead_id' => $this->lead->id, 'invoice_document_id' => $invoice->id, 'currency' => 'NZD', 'amount' => 100, 'paid_at' => '2026-11-01', 'reference' => 'ALLOCATED']);
        $this->confirm($this->proof(), $this->invoice(500), 'ignored', 100, ['payment_mode' => 'existing', 'finance_payment_id' => $payment->id])->assertUnprocessable();
    }

    public function test_only_active_admin_and_finance_can_change_financial_evidence(): void
    {
        $invoice = $this->invoice();
        foreach (['immigration', 'immigration_manager', 'immigration_adviser', 'sales', 'lead', 'agent', 'education'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $response = $this->confirm($this->proof(), $invoice, 'ROLE-'.$role);
            $this->assertContains($response->status(), [403, 404]);
        }
        $this->actingAs(User::factory()->create(['role' => 'finance', 'is_active' => false]));
        $this->confirm($this->proof(), $invoice, 'INACTIVE')->assertForbidden();
        $this->assertDatabaseCount('case_finance_payments', 0);
    }

    public function test_invalid_amount_date_or_reference_cannot_change_a_proof(): void
    {
        $invoice = $this->invoice();
        foreach ([['amount' => 0], ['amount' => 4.123], ['amount' => 'NaN'], ['paid_at' => 'invalid'], ['reference' => ''], ['payment_mode' => 'missing']] as $extra) {
            $proof = $this->proof();
            $this->confirm($proof, $invoice, 'VALID', 400, $extra)->assertUnprocessable();
            $this->assertSame('Submitted', $proof->fresh()->status);
        }
        $this->assertDatabaseCount('case_finance_payments', 0);
    }

    public function test_needs_attention_applies_to_one_file_with_an_explanation(): void
    {
        $one = $this->proof();
        $two = $this->proof();
        $url = "/admin/leads/{$this->lead->id}/documents/{$one->id}/status";
        $this->postJson($url, ['status' => 'Rejected'])->assertUnprocessable();
        $this->postJson($url, ['status' => 'Rejected', 'note' => 'Please provide a readable transfer reference.'])->assertRedirect();
        $this->assertSame('Rejected', $one->fresh()->status);
        $this->assertSame('Submitted', $two->fresh()->status);
        $this->assertSame($this->finance->id, $one->fresh()->reviewed_by);
        $this->assertDatabaseCount('case_finance_payments', 0);
    }

    public function test_confirmed_evidence_cannot_be_rejected_or_deleted(): void
    {
        $invoice = $this->invoice();
        $proof = $this->proof();
        $this->confirm($proof, $invoice, 'IMMUTABLE')->assertRedirect();
        $this->postJson("/admin/leads/{$this->lead->id}/documents/{$proof->id}/status", ['status' => 'Rejected', 'note' => 'Change'])->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->deleteJson("/admin/leads/{$this->lead->id}/documents/{$proof->id}")->assertUnprocessable();
        Storage::disk('local')->assertExists($proof->file_path);
        $this->assertSame('Approved', $proof->fresh()->status);
    }

    public function test_legacy_approved_documents_are_preserved_without_creating_money(): void
    {
        $invoice = $this->invoice();
        $invoice->update(['status' => 'Approved']);
        $proof = $this->proof();
        $proof->update(['status' => 'Approved']);
        $this->confirm($proof, $invoice, 'LEGACY')->assertRedirect();
        $this->assertDatabaseCount('case_finance_payments', 0);
        $this->assertTrue(app(PaymentProofService::class)->invoiceSummary($invoice->fresh())['paid']);
    }

    public function test_currency_is_per_invoice_and_foreign_receipts_do_not_reduce_case_totals(): void
    {
        $financial = CaseFinancial::create(['lead_id' => $this->lead->id, 'currency' => 'NZD', 'service_fee_chargeable' => 1200]);
        $usd = $this->invoice(1000, 'USD');
        $this->confirm($this->proof(), $usd, 'USD-TRANSFER', 200)->assertRedirect();
        $this->assertSame(0.0, $financial->fresh()->totalPaid());
        $this->assertDatabaseHas('case_finance_payments', ['amount' => 200, 'currency' => 'USD']);
        $nzd = $this->invoice(1200, 'NZD');
        $this->confirm($this->proof(), $nzd, 'ignored', 200, ['payment_mode' => 'existing', 'finance_payment_id' => CaseFinancePayment::sole()->id])->assertUnprocessable();
    }

    public function test_failed_review_rolls_back_money_and_document_state(): void
    {
        $invoice = $this->invoice();
        $proof = $this->proof();
        Event::listen('eloquent.creating: '.ActivityLog::class, function (ActivityLog $log) {
            if ($log->action === 'payment_proof.reviewed') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->confirm($proof, $invoice, 'ROLLBACK');
            $this->fail('Expected synthetic failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertSame('Submitted', $proof->fresh()->status);
        $this->assertDatabaseCount('case_finance_payments', 0);
        $this->assertDatabaseCount('payment_proof_reviews', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_review_options_are_scoped_and_mark_immigration_staff_read_only(): void
    {
        $invoice = $this->invoice();
        $proof = $this->proof();
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Case', 'is_immigration_case' => true]);
        $this->invoice(500, 'NZD', $other);
        $url = "/clients/{$this->lead->id}/payment-proofs/{$proof->id}/review-options";
        $this->getJson($url)->assertOk()->assertJsonPath('can_review', true)->assertJsonCount(1, 'invoices')->assertJsonPath('invoices.0.id', $invoice->id);
        $this->actingAs(User::factory()->create(['role' => 'immigration']))->getJson($url)->assertOk()->assertJsonPath('can_review', false);
        $this->actingAs(User::factory()->create(['role' => 'lead']))->getJson($url)->assertForbidden();
        $this->actingAs($this->finance)->getJson("/clients/{$other->id}/payment-proofs/{$proof->id}/review-options")->assertNotFound();
    }

    public function test_batch_upload_and_later_upload_keep_all_previous_proofs(): void
    {
        $invoice = $this->invoice();
        $old = $this->proof();
        $automation = $this->mock(EmailAutomationService::class);
        $automation->shouldReceive('fire')->twice()->with('immigration.proof.uploaded', \Mockery::type(Lead::class), \Mockery::type('array'))->andReturn(false);
        $url = '/engagement/synthetic-payment-token/proof-of-payment';
        $this->post($url, ['invoice_id' => $invoice->id, 'files' => [
            UploadedFile::fake()->create('transfer-one.pdf', 20, 'application/pdf'),
            UploadedFile::fake()->image('transfer-two.png'),
        ]])->assertSessionHasNoErrors();
        $this->post($url, ['invoice_id' => $invoice->id, 'files' => [UploadedFile::fake()->create('transfer-three.pdf', 20, 'application/pdf')]])->assertSessionHasNoErrors();
        $this->assertSame(4, LeadDocument::where('source_variant', 'proof_of_payment')->count());
        $this->assertDatabaseCount('payment_proof_reviews', 3);
        Storage::disk('local')->assertExists($old->file_path);
        foreach (PaymentProofReview::all() as $review) {
            $this->assertSame($invoice->id, $review->invoice_document_id);
            Storage::disk('local')->assertExists($review->document->file_path);
        }
    }

    public function test_invalid_batch_is_atomic_and_does_not_delete_existing_files(): void
    {
        $invoice = $this->invoice();
        $old = $this->proof();
        $files = Storage::disk('local')->allFiles();
        $this->postJson('/engagement/synthetic-payment-token/proof-of-payment', ['invoice_id' => $invoice->id, 'files' => [
            UploadedFile::fake()->create('good.pdf', 20, 'application/pdf'),
            UploadedFile::fake()->create('bad.exe', 20, 'application/x-msdownload'),
        ]])->assertUnprocessable();
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame('Submitted', $old->fresh()->status);
        $this->assertDatabaseCount('payment_proof_reviews', 0);
    }

    public function test_upload_rejects_ambiguous_or_cross_pack_invoice_assignment(): void
    {
        $one = $this->invoice();
        $two = $this->invoice();
        $url = '/engagement/synthetic-payment-token/proof-of-payment';
        $this->postJson($url, ['files' => [UploadedFile::fake()->image('proof.png')]])->assertUnprocessable();
        $packInvoice = $this->invoice(200, 'NZD', null, 'other-pack');
        $this->postJson($url, ['invoice_id' => $packInvoice->id, 'files' => [UploadedFile::fake()->image('proof.png')]])->assertUnprocessable();
        $this->assertSame(0, LeadDocument::where('source_variant', 'proof_of_payment')->count());
    }

    public function test_client_pack_shows_partial_balance_and_all_retained_proofs(): void
    {
        $invoice = $this->invoice();
        $proof = $this->proof();
        $this->confirm($proof, $invoice, 'CLIENT-PARTIAL')->assertRedirect();
        $this->get('/engagement/synthetic-payment-token')->assertOk()->assertInertia(fn ($page) => $page
            ->where('invoice_paid', false)
            ->where('documents.0.payment_summary.confirmed', 400)
            ->where('documents.0.payment_summary.remaining', 800)
            ->where('proofs.0.proof_review.invoice_id', $invoice->id)
        );
    }

    public function test_finance_queue_includes_each_file_and_reviewer_details(): void
    {
        $invoice = $this->invoice();
        $one = $this->proof();
        $two = $this->proof();
        $this->confirm($one, $invoice, 'QUEUE-1')->assertRedirect();
        $this->get('/portal/finance/invoice')->assertOk()->assertInertia(fn ($page) => $page
            ->has('proofs', 2)
            ->where('proofs', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $one->id && $row['reviewed_by'] === $this->finance->name))
        );
    }

    public function test_currency_filter_also_applies_to_finance_ar_mirroring_and_reconciliation(): void
    {
        CaseFinancial::create(['lead_id' => $this->lead->id, 'currency' => 'NZD', 'service_fee_chargeable' => 1200]);
        $invoice = $this->invoice(1000, 'USD');
        $this->confirm($this->proof(), $invoice, 'FOREIGN-AR', 200)->assertRedirect();
        $ar = \App\Models\FinanceReceivable::where('lead_id', $this->lead->id)->sole();
        $this->assertSame(0.0, (float) $ar->payments()->sum('amount'));
        app(\App\Services\Finance\FinanceSyncService::class)->syncForLead($this->lead);
        $this->assertSame(0.0, (float) $ar->payments()->sum('amount'));
    }

    public function test_installment_receipt_retains_currency_and_can_be_linked_as_supporting_proof(): void
    {
        $invoice = $this->invoice(1000, 'USD');
        $plan = \App\Models\InstallmentPlan::create(['lead_id' => $this->lead->id, 'currency' => 'USD', 'status' => 'active',
            'payment_type' => 'pay_later', 'plan_months' => 3, 'weeks' => 13, 'total_amount' => 1000,
            'deposit_percent' => 20, 'deposit_amount' => 200, 'inz_fee' => 0, 'financed_amount' => 800, 'weekly_amount' => 61.54]);
        $installment = $plan->installments()->create(['sequence' => 0, 'kind' => 'deposit', 'due_date' => '2026-11-01', 'amount' => 200, 'status' => 'due']);
        $payment = app(\App\Services\Immigration\InstallmentPlanService::class)->recordPayment($installment, ['reference' => 'USD-INSTALMENT'], $this->finance);
        $this->assertSame('USD', $payment->currency);
        $this->confirm($this->proof(), $invoice, 'ignored', 200, ['payment_mode' => 'existing', 'finance_payment_id' => $payment->id])->assertRedirect();
        $this->assertDatabaseCount('case_finance_payments', 1);
    }

    public function test_linked_receipt_and_invoice_cannot_be_deleted_before_their_files(): void
    {
        $invoice = $this->invoice();
        Storage::disk('local')->put($invoice->file_path, '%PDF invoice');
        $this->confirm($this->proof(), $invoice, 'KEEP-EVIDENCE')->assertRedirect();
        $payment = CaseFinancePayment::sole();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->deleteJson("/portal/immigration/cases/{$this->lead->id}/financials/payments/{$payment->id}")->assertUnprocessable();
        $this->deleteJson("/admin/leads/{$this->lead->id}/invoice/documents")->assertUnprocessable();
        Storage::disk('local')->assertExists($invoice->file_path);
        $this->assertDatabaseCount('case_finance_payments', 1);
    }

    public function test_invoice_document_approval_cannot_bypass_payment_review(): void
    {
        $invoice = $this->invoice();
        $url = "/admin/leads/{$this->lead->id}/documents/{$invoice->id}/status";
        $this->postJson($url, ['status' => 'Approved'])->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->postJson($url, ['status' => 'Approved'])->assertForbidden();
        $this->assertSame('StaffShared', $invoice->fresh()->status);
    }

    public function test_failed_batch_association_rolls_back_files_and_database_records(): void
    {
        $invoice = $this->invoice();
        $old = $this->proof();
        $files = Storage::disk('local')->allFiles();
        Event::listen('eloquent.creating: '.PaymentProofReview::class, fn () => throw new \RuntimeException('Synthetic association failure'));
        $this->withoutExceptionHandling();
        try {
            $this->post('/engagement/synthetic-payment-token/proof-of-payment', ['invoice_id' => $invoice->id, 'files' => [UploadedFile::fake()->image('new-proof.png')]]);
            $this->fail('Expected synthetic association failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic association failure', $exception->getMessage());
        }
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame(1, LeadDocument::where('source_variant', 'proof_of_payment')->count());
        Storage::disk('local')->assertExists($old->file_path);
    }

    public function test_partial_proof_does_not_clear_the_overdue_finding_but_full_invoice_does(): void
    {
        $invoice = $this->invoice();
        $invoice->forceFill(['created_at' => now()->subDays(20)])->saveQuietly();
        $this->confirm($this->proof(), $invoice, 'OVERDUE-PARTIAL', 400)->assertRedirect();
        $rule = app(\App\Services\Immigration\Findings\Rules\InvoiceOverdueRule::class);
        $this->assertNotEmpty($rule->evaluate($this->lead)->findings);
        $this->confirm($this->proof(), $invoice, 'OVERDUE-FULL', 800)->assertRedirect();
        $this->assertEmpty($rule->evaluate($this->lead)->findings);
        $this->assertSame('Invoice Paid', $this->lead->fresh()->immigration_stage);
    }

    public function test_bank_currency_mismatch_is_not_hidden_by_subsequent_case_currency_reconciliation(): void
    {
        $financial = CaseFinancial::create(['lead_id' => $this->lead->id, 'currency' => 'NZD', 'service_fee_chargeable' => 1200]);
        $invoice = $this->invoice(1200, 'NZD');
        $this->confirm($this->proof(), $invoice, 'CURRENCY-CHANGE', 400)->assertRedirect();
        $ar = \App\Models\FinanceReceivable::where('lead_id', $this->lead->id)->sole();
        $this->assertSame(400.0, (float) $ar->payments()->sum('amount'));
        $financial->update(['currency' => 'USD']);
        $this->assertSame(0.0, (float) $ar->payments()->sum('amount'));
        $this->assertSame(400.0, app(PaymentProofService::class)->invoiceSummary($invoice->fresh())['confirmed']);
    }

    public function test_generator_preserves_usd_for_invoice_review(): void
    {
        $invoice = app(\App\Services\Immigration\InvoiceGenerator::class)->generate($this->lead, ['currency' => 'USD', 'silent' => true]);
        $this->assertSame('USD', app(PaymentProofService::class)->currency($invoice));
        $this->assertSame('USD', $invoice->fresh()->notes['engagement_currency']);
    }

    public function test_non_positive_existing_receipt_is_not_confirmable(): void
    {
        $invoice = $this->invoice();
        $receipt = CaseFinancePayment::create(['lead_id' => $this->lead->id, 'amount' => 0, 'currency' => 'NZD', 'paid_at' => today(), 'recorded_by' => $this->finance->id]);
        $this->confirm($this->proof(), $invoice, '', 400, ['payment_mode' => 'existing', 'finance_payment_id' => $receipt->id])->assertUnprocessable();
    }

    public function test_reconfirmation_clears_previous_attention_reason_and_generic_note_cannot_edit_review(): void
    {
        $proof = $this->proof();
        $proof->update(['status' => 'Rejected', 'note' => 'Unreadable copy']);
        $this->confirm($proof, $this->invoice(), 'CLEAR-OLD-REASON')->assertRedirect();
        $this->postJson("/admin/leads/{$proof->lead_id}/documents/{$proof->id}/send-to-client")->assertUnprocessable();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/admin/document-queue/bulk', ['ids' => [$proof->id], 'action' => 'reject'])->assertUnprocessable();
        $adviser = User::factory()->create(['role' => 'immigration_adviser']);
        $this->actingAs($adviser)->postJson("/portal/immigration-adviser/verification/{$proof->id}", ['action' => 'reject', 'note' => 'Bypass'])->assertUnprocessable();
        $this->assertNull($proof->fresh()->note);
        $this->postJson("/admin/documents/{$proof->id}/note", ['note' => 'Changed elsewhere'])->assertUnprocessable();
        $this->assertSame($this->finance->id, $proof->fresh()->reviewed_by);
    }

    public function test_family_delete_preserves_linked_child_payment_evidence(): void
    {
        $parent = Lead::create(['first_name' => 'Parent', 'last_name' => 'Synthetic', 'is_immigration_case' => true]);
        $dependent = \App\Models\CaseDependent::create(['lead_id' => $parent->id, 'linked_lead_id' => $this->lead->id, 'relationship' => 'child', 'first_name' => 'Synthetic']);
        $proof = $this->proof();
        $this->confirm($proof, $this->invoice(), 'FAMILY-RETAIN')->assertRedirect();
        $client = User::factory()->create(['role' => 'lead', 'lead_id' => $parent->id]);
        $this->actingAs($client)->deleteJson("/portal/lead/family/{$dependent->id}/documents/{$proof->id}")->assertUnprocessable();
        Storage::disk('local')->assertExists($proof->file_path);
        $this->assertNotNull($proof->fresh());
    }

    public function test_cancelled_invoice_does_not_block_settled_client_invoice(): void
    {
        $cancelled = $this->invoice();
        $cancelled->update(['status' => 'Rejected']);
        $invoice = $this->invoice();
        $this->confirm($this->proof(), $invoice, 'VALID-INVOICE-FULL', 1200)->assertRedirect();
        $this->get('/engagement/synthetic-payment-token')->assertOk()->assertInertia(fn ($page) => $page->where('invoice_paid', true)->has('documents', 1));
    }

    public function test_legacy_invoice_currency_must_be_confirmed_before_recording_money(): void
    {
        $invoice = $this->invoice();
        $invoice->update(['notes' => null]);
        $proof = $this->proof();
        $this->confirm($proof, $invoice, 'LEGACY-USD')->assertUnprocessable()->assertJsonValidationErrors('invoice_currency');
        $this->assertDatabaseCount('case_finance_payments', 0);
        $this->confirm($proof, $invoice, 'LEGACY-USD', 400, ['invoice_currency' => 'USD'])->assertRedirect();
        $this->assertSame('USD', $invoice->fresh()->notes['engagement_currency']);
        $this->assertSame('USD', CaseFinancePayment::first()->currency);
        $this->confirm($this->proof(), $invoice, 'STALE-CURRENCY', 400, ['invoice_currency' => 'NZD'])->assertUnprocessable()->assertJsonValidationErrors('invoice_currency');
        $this->assertDatabaseCount('case_finance_payments', 1);
    }
}
