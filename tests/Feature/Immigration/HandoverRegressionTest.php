<?php

namespace Tests\Feature\Immigration;

use App\Models\CaseFinancePayment;
use App\Models\Agreement;
use App\Models\CaseFinancial;
use App\Models\FinanceReceivable;
use App\Models\InstallmentPlan;
use App\Models\Lead;
use App\Models\User;
use App\Services\Immigration\InstallmentPlanService;
use App\Services\Immigration\VisaApplicationService;
use App\Services\EmailAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandoverRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function lead(): Lead
    {
        return Lead::create(['first_name' => 'Test', 'last_name' => 'Client', 'is_immigration_case' => true, 'immigration_stage' => 'Approved Visa']);
    }

    private function plan(Lead $lead): InstallmentPlan
    {
        config(['immigration.installments.plan_presets' => [3 => 1]]);
        $service = app(InstallmentPlanService::class);
        return $service->activate($service->draft($lead, ['total_amount' => 5000, 'inz_fee' => 1000, 'plan_months' => 3, 'deposit_percent' => 25]));
    }

    public function test_staff_can_start_the_next_visa_from_the_case_profile(): void
    {
        $lead = $this->lead();
        $staff = User::factory()->create(['role' => 'immigration', 'is_active' => true]);
        $this->actingAs($staff)->post("/portal/immigration/cases/{$lead->id}/next-visa", ['visa_label' => 'Work Visa'])->assertRedirect();
        $this->assertDatabaseCount('visa_applications', 2);
    }

    public function test_next_visa_rejects_an_unapproved_case(): void
    {
        $lead = $this->lead();
        $lead->update(['immigration_stage' => 'For Assessment']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(VisaApplicationService::class)->startNextVisa($lead, 'Work Visa', null, null);
    }

    public function test_next_visa_cannot_create_a_second_successor_on_retry(): void
    {
        $lead = $this->lead();
        $service = app(VisaApplicationService::class);
        $service->startNextVisa($lead, 'Work Visa', null, null);
        try {
            $service->startNextVisa($lead, 'Work Visa', null, null);
            $this->fail('A closed case must not create another successor.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertDatabaseCount('visa_applications', 2);
    }

    public function test_next_visa_preserves_the_referring_agent_and_case_assignment(): void
    {
        $lead = $this->lead();
        $agent = User::factory()->create(['role' => 'agent']);
        $staff = User::factory()->create(['role' => 'immigration']);
        $lead->update(['agent_id' => $agent->id, 'assigned_to' => $staff->id]);
        $new = app(VisaApplicationService::class)->startNextVisa($lead, 'Work Visa', null, $staff);
        $this->assertEquals($agent->id, $new->agent_id);
        $this->assertEquals($staff->id, $new->assigned_to);
        $this->assertSame('For Assessment', $new->immigration_stage);
    }

    public function test_disabled_onboarding_email_is_not_marked_as_sent(): void
    {
        $agreement = Agreement::create([
            'lead_id' => $this->lead()->id,
            'generated_by_user_id' => User::factory()->create()->id,
            'title' => 'Test', 'content_rendered' => 'Test',
            'status' => 'sent', 'sent_at' => now()->subDays(10),
        ]);
        $this->mock(EmailAutomationService::class)->shouldReceive('fireAnyRecipient')->once()->andReturn(false);
        $this->artisan('immigration:onboarding-reminders')->assertSuccessful();
        $this->assertNull($agreement->fresh()->sign_reminded_at);
    }

    public function test_staff_only_onboarding_reminder_is_queued_once(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $manager = User::factory()->create(['role' => 'immigration_manager']);
        $lead = $this->lead();
        $agreement = Agreement::create([
            'lead_id' => $lead->id, 'generated_by_user_id' => $manager->id,
            'title' => 'Test', 'content_rendered' => 'Test',
            'status' => 'sent', 'sent_at' => now()->subDays(10),
        ]);
        \App\Models\MessageTemplate::create([
            'key' => 'reminder_test', 'department' => 'immigration', 'name' => 'Reminder',
            'channels' => ['email'], 'email_subject' => 'Reminder', 'email_body' => 'Test', 'is_active' => true,
        ]);
        \App\Models\EmailAutomationMessage::create([
            'event_key' => 'immigration.engagement.reminder', 'recipient' => 'manager',
            'template_key' => 'reminder_test', 'channel' => 'email', 'enabled' => true, 'sort_order' => 0,
        ]);
        // Existing callers still use fire() to detect client delivery only.
        $this->assertFalse(app(EmailAutomationService::class)->fire('immigration.engagement.reminder', $lead));
        \Illuminate\Support\Facades\Mail::fake();
        $this->artisan('immigration:onboarding-reminders')->assertSuccessful();
        $this->artisan('immigration:onboarding-reminders')->assertSuccessful();
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\TemplatedMessage::class, 1);
        $this->assertNotNull($agreement->fresh()->sign_reminded_at);
    }

    public function test_payment_retry_does_not_duplicate_either_ledger(): void
    {
        $lead = $this->lead();
        $plan = $this->plan($lead);
        $deposit = $plan->installments()->first();
        $stale = $deposit->fresh();
        $service = app(InstallmentPlanService::class);
        $first = $service->recordPayment($deposit);
        $retry = $service->recordPayment($stale);
        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('case_finance_payments', 1);
        $this->assertDatabaseCount('finance_payments', 1);
    }

    public function test_deleting_a_receipt_reopens_its_installment_and_completed_plan(): void
    {
        $plan = $this->plan($this->lead());
        $service = app(InstallmentPlanService::class);
        $installments = $plan->installments()->get();
        foreach ($installments as $installment) {
            $service->recordPayment($installment);
        }
        $this->assertSame('completed', $plan->fresh()->status);
        $installments[0]->fresh()->financePayment->delete();
        $this->assertSame('due', $installments[0]->fresh()->status);
        $this->assertNull($installments[0]->fresh()->finance_payment_id);
        $this->assertSame('active', $plan->fresh()->status);
        $this->assertDatabaseCount('finance_payments', 1);
    }

    public function test_first_finance_sync_includes_historical_case_receipts(): void
    {
        $lead = $this->lead();
        $financial = CaseFinancial::withoutEvents(fn () => CaseFinancial::create(['lead_id' => $lead->id, 'service_fee_chargeable' => 3000, 'inz_fee' => 0, 'currency' => 'NZD']));
        CaseFinancePayment::withoutEvents(fn () => CaseFinancePayment::create(['lead_id' => $lead->id, 'amount' => 1200, 'paid_at' => now()]));
        $financial->save();
        $financial->save();
        $ar = FinanceReceivable::where('lead_id', $lead->id)->firstOrFail();
        $this->assertEquals(1200, $ar->amount_paid);
        $this->assertSame(1, $ar->payments()->count());
    }

    public function test_clearing_case_fees_clears_the_linked_receivable(): void
    {
        $lead = $this->lead();
        $financial = CaseFinancial::create(['lead_id' => $lead->id, 'service_fee_chargeable' => 3000, 'inz_fee' => 0, 'currency' => 'NZD']);
        $financial->update(['service_fee_chargeable' => 0]);
        $this->assertEquals(0, FinanceReceivable::where('lead_id', $lead->id)->firstOrFail()->amount);
    }
}
