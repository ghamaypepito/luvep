<?php

namespace Tests\Feature\Finance;

use App\Models\ActivityLog;
use App\Models\CaseFinancePayment;
use App\Models\CaseFinancial;
use App\Models\FinancePayable;
use App\Models\FinanceReceivable;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Services\Finance\PortalInvoiceReceivables;
use App\Services\PaymentProofService;
use Inertia\Testing\AssertableInertia as Assert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalInvoiceReceivablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_records_an_actual_student_visa_receipt_then_flags_internal_settlement(): void
    {
        Mail::fake();
        $lead = Lead::create(['first_name' => 'Example', 'last_name' => 'Student', 'is_immigration_case' => true, 'inz_visa_type' => 'Student Visa']);
        $invoice = $this->document($lead, 'INV-101', 500);
        $otherInvoice = $this->document($lead, 'INV-102', 100);
        app(PortalInvoiceReceivables::class)->post($invoice);
        app(PortalInvoiceReceivables::class)->post($otherInvoice);
        $receivable = $invoice->receivable()->firstOrFail();
        $url = '/portal/finance/receivables/'.$receivable->id;
        $finance = User::factory()->create(['role' => 'finance']);
        $this->actingAs($finance);

        $this->patch($url.'/internal-settlement', ['pending' => true])->assertSessionHasErrors('pending');
        $this->post($url.'/payments', ['amount' => 500, 'paid_on' => now()->toDateString()])->assertSessionHasErrors('reference');
        $this->post($url.'/payments', ['amount' => 500, 'paid_on' => now()->addDay()->toDateString(), 'reference' => 'Future'])->assertSessionHasErrors('paid_on');
        $this->assertDatabaseCount('finance_payments', 0);

        $receipt = ['amount' => 500, 'paid_on' => now()->subDay()->toDateString(), 'reference' => 'Transfer 101', 'notes' => 'Verified against the bank statement'];
        $this->post($url.'/payments', $receipt)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url.'/payments', $receipt)->assertSessionHasErrors('reference');

        $payment = $invoice->invoicePayments()->sole();
        $this->assertSame($receipt['paid_on'], $payment->paid_at->toDateString());
        $this->assertSame('paid', $receivable->fresh()->payment_status);
        $this->assertSame($receipt['notes'], $receivable->payments()->sole()->notes);
        $this->assertTrue(app(PaymentProofService::class)->invoiceSummary($invoice->fresh())['paid']);
        $this->assertFalse(app(PaymentProofService::class)->invoiceSummary($otherInvoice->fresh())['paid']);
        $this->assertFalse($receivable->fresh()->internal_settlement_pending);

        $this->patch($url.'/internal-settlement', ['pending' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->patch($url.'/internal-settlement', ['pending' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/portal/finance/receivables?q=INV-101')->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.payment_status', 'paid')
            ->where('rows.0.internal_settlement_pending', true));
        $this->assertSame(1, ActivityLog::where('action', 'finance.invoice_internal_settlement_changed')->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'finance.invoice_payment_recorded', 'user_id' => $finance->id, 'entity_id' => $receivable->id]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch($url.'/internal-settlement', ['pending' => false])->assertRedirect()->assertSessionHasNoErrors();
        $this->delete($url.'/payments/'.$receivable->payments()->sole()->id)->assertUnprocessable();
        $this->delete("/portal/immigration/cases/{$lead->id}/financials/payments/{$payment->id}")->assertSessionHasErrors('payment');
        $this->assertFalse($receivable->fresh()->internal_settlement_pending);
        $this->assertDatabaseCount('case_finance_payments', 1);
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertSame('INV-101', $invoice->fresh()->invoice_number);
        $this->assertSame('fixture.pdf', $invoice->fresh()->file_path);
        Mail::assertNothingOutgoing();
    }

    public function test_extra_portal_access_cannot_record_student_visa_receipts_or_change_internal_settlement(): void
    {
        $lead = Lead::create(['first_name' => 'Example', 'last_name' => 'Student', 'is_immigration_case' => true, 'inz_visa_type' => 'Student Visa']);
        $invoice = $this->document($lead, 'INV-103', 500);
        app(PortalInvoiceReceivables::class)->post($invoice);
        $receivable = $invoice->receivable()->firstOrFail();
        $url = '/portal/finance/receivables/'.$receivable->id;
        $this->actingAs(User::factory()->create(['role' => 'immigration', 'portal_access' => ['finance']]))
            ->post($url.'/payments', ['amount' => 500, 'paid_on' => now()->toDateString(), 'reference' => 'Transfer 103'])->assertForbidden();
        $this->patch($url.'/internal-settlement', ['pending' => true])->assertForbidden();
        $this->assertDatabaseCount('finance_payments', 0);
    }

    public function test_generated_invoice_posts_and_its_case_receipt_updates_only_that_invoice(): void
    {
        Storage::fake('local');
        Mail::fake();
        $lead = Lead::create(['first_name' => 'Example', 'last_name' => 'Client', 'is_immigration_case' => true]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post("/admin/leads/{$lead->id}/invoice/generate", [
                'invoice_number' => 'INV-101', 'invoice_date' => '2026-11-06', 'due_date' => '2026-11-13',
                'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 500]],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $doc = LeadDocument::where('invoice_number', 'INV-101')->sole();
        $entry = FinanceReceivable::sole();
        $this->assertSame($doc->id, $entry->invoice_document_id);
        $this->assertSame('2026-11-06', $entry->issue_date->toDateString());
        $this->assertSame('2026-11-13', $entry->due_date->toDateString());
        $this->assertSame('500.00', $entry->amount);

        CaseFinancePayment::create([
            'lead_id' => $lead->id, 'invoice_document_id' => $doc->id,
            'amount' => 150, 'currency' => 'NZD', 'paid_at' => '2026-11-06', 'reference' => 'Receipt 1',
        ]);
        $this->assertDatabaseCount('finance_receivables', 1);
        $this->assertSame(350.0, $entry->fresh()->balance);
    }

    public function test_issuing_against_a_matching_case_aggregate_reuses_the_ledger_and_moves_allocated_receipts(): void
    {
        $lead = Lead::create(['first_name' => 'Example', 'last_name' => 'Client', 'is_immigration_case' => true]);
        CaseFinancial::create(['lead_id' => $lead->id, 'service_fee_chargeable' => 500, 'currency' => 'NZD']);
        $aggregate = FinanceReceivable::sole();
        $payment = CaseFinancePayment::create([
            'lead_id' => $lead->id, 'amount' => 100, 'currency' => 'NZD', 'paid_at' => '2026-11-06', 'reference' => 'Bank deposit',
        ]);
        $first = $this->document($lead, 'INV-101', 500);
        app(PortalInvoiceReceivables::class)->post($first);
        $this->assertSame($aggregate->id, FinanceReceivable::sole()->id);
        $this->assertSame('INV-101', $aggregate->fresh()->invoice_no);
        $this->assertSame(400.0, $aggregate->fresh()->balance);

        $second = $this->document($lead, 'INV-102', 200);
        app(PortalInvoiceReceivables::class)->post($second);
        $payment->update(['invoice_document_id' => $second->id]);
        $this->assertSame(500.0, $aggregate->fresh()->balance);
        $this->assertSame(100.0, $second->receivable()->firstOrFail()->balance);
        $this->assertDatabaseCount('finance_payments', 1);
    }

    public function test_missing_or_conflicting_document_does_not_duplicate_a_ledger_entry_or_misallocate_receipts(): void
    {
        $lead = Lead::create(['first_name' => 'Example', 'last_name' => 'Client', 'is_immigration_case' => true]);
        $first = $this->document($lead, 'INV-101', 500);
        app(PortalInvoiceReceivables::class)->post($first);
        CaseFinancial::create(['lead_id' => $lead->id, 'service_fee_chargeable' => 500, 'currency' => 'NZD']);
        $pending = $this->document($lead, 'INV-102', 200);
        $previouslyUnallocated = CaseFinancePayment::create([
            'lead_id' => $lead->id, 'amount' => 50, 'currency' => 'NZD', 'paid_at' => '2026-11-06',
        ]);
        $this->assertSame(450.0, $first->receivable()->firstOrFail()->balance);
        $previouslyUnallocated->update(['invoice_document_id' => $pending->id]);
        CaseFinancePayment::create([
            'lead_id' => $lead->id, 'invoice_document_id' => $pending->id,
            'amount' => 100, 'currency' => 'NZD', 'paid_at' => '2026-11-06',
        ]);
        $this->assertDatabaseCount('finance_receivables', 1);
        $this->assertDatabaseCount('finance_payments', 0);
        $this->assertSame(500.0, $first->receivable()->firstOrFail()->balance);
    }

    public function test_sharing_a_saved_invoice_through_documents_posts_it_without_email(): void
    {
        Mail::fake();
        $lead = Lead::create(['first_name' => 'Example', 'last_name' => 'Client', 'is_immigration_case' => true]);
        $doc = $this->document($lead, 'INV-101', 500);
        $notes = $doc->notes;
        $notes['engagement_pack_id'] = 'saved-draft-pack';
        $doc->update(['notes' => $notes]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post("/admin/leads/{$lead->id}/documents/{$doc->id}/send-to-client")
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($doc->id, FinanceReceivable::sole()->invoice_document_id);
        Mail::assertNothingOutgoing();
    }

    public function test_finance_totals_keep_currencies_separate_in_both_ledgers_and_collections(): void
    {
        foreach (['NZD' => 100, 'USD' => 50] as $currency => $amount) {
            $ar = FinanceReceivable::create(['invoice_no' => 'INV-'.$currency, 'client_name' => 'Customer',
                'currency' => $currency, 'amount' => $amount, 'issue_date' => '2026-11-06', 'due_date' => '2026-11-13', 'status' => 'sent']);
            $ap = FinancePayable::create(['bill_no' => 'BILL-'.$currency, 'vendor_name' => 'Supplier',
                'currency' => $currency, 'amount' => $amount, 'issue_date' => '2026-11-06', 'due_date' => '2026-11-13', 'status' => 'approved']);
            foreach ([$ar, $ap] as $entry) {
                $entry->payments()->create(['amount' => 10, 'paid_on' => '2026-11-06']);
            }
        }

        $this->actingAs(User::factory()->create(['role' => 'finance']));
        $this->get('/portal/finance/receivables')->assertInertia(fn (Assert $page) => $page
            ->has('rows', 2)->where('summary.outstanding', 90)->where('summary.collected', 10));
        $this->get('/portal/finance/payables')->assertInertia(fn (Assert $page) => $page
            ->has('rows', 2)->where('summary.outstanding', 90)->where('summary.paid', 10));
        $this->get('/portal/finance/reports?report=collections&start=2026-11-06&end=2026-11-06')
            ->assertInertia(fn (Assert $page) => $page->has('data.received', 1)->has('data.paid', 1)
                ->where('data.received_total', 10)->where('data.paid_total', 10));
        $this->get('/portal/finance/reports?report=receivables_aging&currency=USD')
            ->assertInertia(fn (Assert $page) => $page->where('currency', 'USD')
                ->where('data.total', 40)->where('data.rows.0.ref', 'INV-USD'));
    }

    private function document(Lead $lead, string $number, int $total): LeadDocument
    {
        return $lead->documents()->create([
            'source' => 'generated', 'source_variant' => 'invoice', 'status' => 'Submitted',
            'invoice_number' => $number, 'invoice_total' => $total, 'original_name' => $number.'.pdf', 'file_path' => 'fixture.pdf',
            'notes' => ['engagement_currency' => 'NZD', 'invoice_finance' => [
                'issue_date' => '2026-11-06', 'due_date' => '2026-11-13', 'draft' => false,
            ]],
        ]);
    }
}
