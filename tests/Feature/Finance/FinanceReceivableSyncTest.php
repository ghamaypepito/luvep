<?php

namespace Tests\Feature\Finance;

use App\Models\CaseFinancePayment;
use App\Models\CaseFinancial;
use App\Models\FinanceReceivable;
use App\Models\InstallmentPlan;
use App\Models\Lead;
use App\Services\Immigration\InstallmentPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Immigration case money flows into the Finance portal's Accounts Receivable:
 * one AR invoice per case (from its CaseFinancial or installment-plan total),
 * and every case payment (manual or installment) mirrored into its ledger.
 */
class FinanceReceivableSyncTest extends TestCase
{
    use RefreshDatabase;

    private function caseLead(): Lead
    {
        return Lead::create([
            'lead_id' => Lead::generateLeadId(), 'first_name' => 'Maria', 'last_name' => 'Santos',
            'email' => 'maria@example.test', 'is_immigration_case' => true,
            'inz_visa_type' => 'Skilled Migrant Resident Visa', 'status' => 'New Leads',
        ]);
    }

    public function test_case_financial_creates_a_receivable(): void
    {
        $lead = $this->caseLead();

        $cf = CaseFinancial::create([
            'lead_id' => $lead->id, 'service_fee_chargeable' => 6000, 'inz_fee' => 5510,
            'currency' => 'NZD', 'invoice_no' => 'INV-1001',
        ]);

        $ar = FinanceReceivable::where('lead_id', $lead->id)->first();
        $this->assertNotNull($ar, 'An AR invoice should be created for the case.');
        $this->assertSame('INV-1001', $ar->invoice_no);
        $this->assertEqualsWithDelta((float) $cf->totalPayable(), (float) $ar->amount, 0.01);
        $this->assertSame('Maria Santos', $ar->client_name);
    }

    public function test_case_payment_is_mirrored_and_removed(): void
    {
        $lead = $this->caseLead();
        CaseFinancial::create(['lead_id' => $lead->id, 'service_fee_chargeable' => 6000, 'inz_fee' => 5510, 'currency' => 'NZD']);

        $payment = CaseFinancePayment::create([
            'lead_id' => $lead->id, 'paid_at' => now(), 'amount' => 2000, 'method' => 'Bank transfer', 'reference' => 'Deposit',
        ]);

        $ar = FinanceReceivable::where('lead_id', $lead->id)->first()->fresh();
        $this->assertEqualsWithDelta(2000.0, (float) $ar->amount_paid, 0.01);
        $this->assertSame('partial', $ar->payment_status);
        $this->assertSame(1, $ar->payments()->count());

        // Deleting the case payment removes its mirror.
        $payment->delete();
        $ar = $ar->fresh();
        $this->assertEqualsWithDelta(0.0, (float) $ar->amount_paid, 0.01);
        $this->assertSame(0, $ar->payments()->count());
    }

    public function test_installment_plan_drives_the_receivable_amount_without_an_invoice(): void
    {
        $lead = $this->caseLead();

        $plan = app(InstallmentPlanService::class)->draft($lead, [
            'total_amount' => 5000, 'inz_fee' => 1000, 'plan_months' => 3, 'deposit_percent' => 25,
        ], null);
        app(InstallmentPlanService::class)->activate($plan);

        $ar = FinanceReceivable::where('lead_id', $lead->id)->first();
        $this->assertNotNull($ar, 'A plan should create an AR even before an invoice is generated.');
        $this->assertEqualsWithDelta(5000.0, (float) $ar->amount, 0.01);

        // Paying the deposit installment mirrors into the AR ledger.
        $deposit = $plan->installments()->where('kind', 'deposit')->first();
        app(InstallmentPlanService::class)->recordPayment($deposit, ['method' => 'Card'], null);

        $ar = $ar->fresh();
        $this->assertEqualsWithDelta((float) $deposit->amount, (float) $ar->amount_paid, 0.01);
    }

    public function test_sync_is_idempotent_no_duplicate_receivables(): void
    {
        $lead = $this->caseLead();

        $cf = CaseFinancial::create(['lead_id' => $lead->id, 'service_fee_chargeable' => 3000, 'inz_fee' => 1000, 'currency' => 'NZD']);
        $cf->update(['service_fee_chargeable' => 3500]); // triggers another saved event
        $cf->update(['inz_fee' => 1200]);

        $this->assertSame(1, FinanceReceivable::where('lead_id', $lead->id)->count());
        $this->assertEqualsWithDelta((float) $cf->fresh()->totalPayable(), (float) FinanceReceivable::where('lead_id', $lead->id)->first()->amount, 0.01);
    }
}
