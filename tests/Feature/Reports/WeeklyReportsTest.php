<?php

namespace Tests\Feature\Reports;

use App\Models\FinanceReceivable;
use App\Models\HrEmployee;
use App\Models\HrPolicy;
use App\Models\HrPolicyAcknowledgement;
use App\Models\HrPolicyVersion;
use App\Models\Lead;
use App\Models\LeadTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WeeklyReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_education_last_week_uses_nz_boundaries_for_stages_and_tasks(): void
    {
        // Already Monday in NZ, still Sunday in UTC.
        $this->travelTo(Carbon::parse('2026-11-08T12:00:00Z'));
        $staff = User::factory()->create(['role' => 'education']);
        foreach (['2026-11-01T10:59:59Z', '2026-11-01T11:00:00Z', '2026-11-08T10:59:59Z', '2026-11-08T11:00:00Z'] as $at) {
            $lead = Lead::create([
                'first_name' => 'Report', 'last_name' => 'Student', 'is_student' => true,
                'education_stage' => 'Proposal Sent', 'stage_history' => [
                    ['stage' => 'Proposal Sent', 'at' => $at, 'department' => 'education'],
                ],
            ]);
            LeadTask::create(['title' => 'Report task', 'lead_id' => $lead->id, 'created_by' => $staff->id, 'department' => 'education', 'completed' => true, 'completed_at' => $at]);
        }
        LeadTask::create(['title' => 'Other team', 'lead_id' => $lead->id, 'created_by' => $staff->id, 'department' => 'finance', 'completed' => true, 'completed_at' => '2026-11-04 00:00:00']);

        $this->actingAs($staff)->get('/portal/education/reports?preset=this_week')->assertInertia(fn (Assert $page) => $page
            ->where('range.previous.from', '2026-11-02')
            ->where('range.previous.to', '2026-11-02'));

        $this->get('/portal/education/reports?preset=last_week')->assertInertia(fn (Assert $page) => $page
            ->where('range.preset', 'last_week')
            ->where('range.from', '2026-11-02')
            ->where('range.to', '2026-11-08')
            ->where('range.days', 7)
            ->where('departments.education.event_count', 2)
            ->where('weeklyReport.tasks.completed', 2)
            ->has('weeklyReport.tasks.recent', 2));
    }

    public function test_weekly_note_persists_through_rollover_and_stays_in_its_portal(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04T00:00:00Z'));
        $staff = User::factory()->create(['role' => 'education']);
        $this->actingAs($staff)->from('/portal/education/reports?preset=this_week')
            ->post('/portal/education/reports/note', ['week_start' => '2026-11-02', 'note' => 'Follow up on offers.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->travelTo(Carbon::parse('2026-11-09T00:00:00Z'));

        $this->get('/portal/education/reports?preset=last_week')->assertInertia(fn (Assert $page) => $page
            ->where('weeklyReport.note', 'Follow up on offers.'));
        $this->get('/portal/education/reports?preset=this_week')->assertInertia(fn (Assert $page) => $page
            ->where('weeklyReport.note', ''));
        $this->post('/portal/finance/reports/note', ['week_start' => '2026-11-02', 'note' => 'Wrong portal'])->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'finance']))->get('/portal/finance/reports?preset=last_week')
            ->assertInertia(fn (Assert $page) => $page->where('start', '2026-11-02')->where('end', '2026-11-08')->where('weeklyReport.note', ''));
    }

    public function test_last_week_is_available_in_every_department_report(): void
    {
        $this->travelTo(Carbon::parse('2026-11-09T00:00:00Z'));
        foreach (['sales', 'immigration', 'immigration-adviser', 'accommodation', 'finance'] as $portal) {
            $staff = User::factory()->create(['role' => str_replace('-', '_', $portal)]);
            $url = '/portal/'.$portal.'/reports';
            $this->actingAs($staff)->from($url.'?preset=last_week')
                ->post($url.'/note', ['week_start' => '2026-11-02', 'note' => 'Weekly update'])->assertRedirect()->assertSessionHasNoErrors();
            $this->get($url.'?preset=last_week')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('weeklyReport.from', '2026-11-02')->where('weeklyReport.to', '2026-11-08')->where('weeklyReport.note', 'Weekly update'));
        }
        $this->actingAs(User::where('role', 'sales')->firstOrFail())
            ->get('/portal/sales/reports?week_start=2026-11-02')->assertInertia(fn (Assert $page) => $page
                ->where('preset', 'last_week')->where('weeklyReport.note', 'Weekly update'));

        $this->actingAs(User::factory()->create(['role' => 'immigration_adviser']))
            ->get('/portal/immigration-adviser/reports?preset=last_week')->assertInertia(fn (Assert $page) => $page
                ->where('weeklyReport.note', '')->where('weeklyReport.personal', true));

        $external = User::factory()->create(['role' => 'agent', 'portal_access' => ['accommodation', 'finance']]);
        $this->actingAs($external)->get('/portal/accommodation/reports')->assertForbidden();
        $this->post('/portal/finance/reports/note', ['week_start' => '2026-11-02', 'note' => 'Forbidden'])->assertForbidden();
    }

    public function test_finance_last_week_filters_real_payments_and_aging_stays_a_snapshot(): void
    {
        $this->travelTo(Carbon::parse('2026-11-09T00:00:00Z'));
        $invoice = FinanceReceivable::create([
            'invoice_no' => 'WEEKLY-TEST', 'client_name' => 'Test payer', 'amount' => 400,
            'issue_date' => '2026-11-01', 'due_date' => '2026-11-20', 'status' => 'sent',
        ]);
        foreach (['2026-11-01', '2026-11-02', '2026-11-08', '2026-11-09'] as $date) {
            $invoice->payments()->create(['paid_on' => $date, 'amount' => 50, 'method' => 'bank_transfer']);
        }

        $this->actingAs(User::factory()->create(['role' => 'finance']))
            ->get('/portal/finance/reports?report=collections&preset=last_week')
            ->assertInertia(fn (Assert $page) => $page->where('data.received_total', 100)->has('data.received', 2));
        $this->get('/portal/finance/reports?report=receivables_aging&preset=last_week')
            ->assertInertia(fn (Assert $page) => $page->where('data.total', 200)->where('weeklyReport', null));
    }

    public function test_hr_weekly_notes_are_personal_and_only_accept_valid_week_starts(): void
    {
        config(['hr.enabled' => true]);
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-09T00:00:00Z'));
        $owner = User::factory()->create(['role' => 'education']);
        $other = User::factory()->create(['role' => 'education']);
        foreach ([$owner, $other] as $staff) {
            HrEmployee::create(['user_id' => $staff->id, 'status' => 'active']);
        }
        $url = '/portal/hr/reports?preset=last_week';
        $this->actingAs($owner)->from($url)->post('/portal/hr/reports/note', ['week_start' => '2026-11-03', 'note' => 'Invalid'])
            ->assertSessionHasErrors('week_start');
        $this->post('/portal/hr/reports/note', ['week_start' => '2026-11-02', 'note' => 'Personal HR note'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', '2026-11-02')->where('filters.to', '2026-11-08')
            ->where('weeklyReport.note', 'Personal HR note'));
        $this->actingAs($other)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('weeklyReport.note', ''));
    }

    public function test_hr_policy_acknowledgements_use_nz_week_boundaries(): void
    {
        config(['hr.enabled' => true]);
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-09T00:00:00Z'));
        $staff = User::factory()->create(['role' => 'education']);
        HrEmployee::create(['user_id' => $staff->id, 'status' => 'active']);
        $policy = HrPolicy::create(['draft_title' => 'Policy', 'draft_body' => 'Text']);
        $version = HrPolicyVersion::create([
            'hr_policy_id' => $policy->id, 'version' => 1, 'title' => 'Policy', 'body' => 'Text',
            'published_by' => $staff->id, 'published_at' => now()->subDays(10),
        ]);
        HrPolicyAcknowledgement::create([
            'hr_policy_version_id' => $version->id, 'user_id' => $staff->id, 'acknowledged_at' => '2026-11-08 11:30:00',
        ]);

        $url = '/portal/hr/reports?type=policy_acknowledgements&preset=';
        $this->actingAs($staff)->get($url.'this_week')->assertInertia(fn (Assert $page) => $page->has('rows', 1));
        $this->get($url.'last_week')->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    }
}
