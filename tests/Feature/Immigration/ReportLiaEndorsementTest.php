<?php

namespace Tests\Feature\Immigration;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportLiaEndorsementTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_counts_documented_endorsements_in_period_regardless_of_current_assignee_or_stage(): void
    {
        $event = fn ($at, $department = 'immigration') => ['department' => $department, 'stage' => 'Endorsed', 'at' => $at];
        $included = Lead::create(['first_name' => 'In', 'last_name' => 'Period', 'is_immigration_case' => true,
            'immigration_stage' => 'Visa Lodged', 'immigration_assignee' => 'Tarun',
            'stage_history' => [$event('2026-10-20T12:00:00+00:00'), $event('2026-10-21T12:00:00+00:00')]]);
        Lead::create(['first_name' => 'Old', 'last_name' => 'Endorsement', 'is_immigration_case' => true,
            'immigration_assignee' => 'Hendry', 'stage_history' => [$event('2026-09-20T12:00:00+00:00')]]);
        Lead::create(['first_name' => 'Assigned', 'last_name' => 'Only', 'is_immigration_case' => true, 'immigration_assignee' => 'Dev']);
        Lead::create(['first_name' => 'Wrong', 'last_name' => 'Department', 'is_immigration_case' => true,
            'stage_history' => [$event('2026-10-20T12:00:00+00:00', 'education')]]);
        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->get('/portal/immigration/reports?preset=custom&from=2026-10-19&to=2026-10-25')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('pipeline.endorsed_lia', 1)->has('namedIntake.endorsed_lia', 1)
            ->where('namedIntake.endorsed_lia.0.id', $included->id)
            ->where('namedIntake.endorsed_lia.0.name', 'In Period')
            ->where('namedIntake.endorsed_lia.0.date', '2026-10-21T12:00:00+00:00')
            ->missing('pipeline.endorsed_hendry')->missing('pipeline.endorsed_dev'));
    }

    public function test_default_week_report_includes_new_clients_interim_and_rfi_names(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-21 12:00:00'));
        foreach (['Interim Visa Issued', 'Request for Information', 'Approved Visa', 'Decline Visa'] as $stage) {
            Lead::create(['first_name' => $stage, 'last_name' => 'Client', 'is_immigration_case' => true,
                'immigration_stage' => $stage, 'immigration_converted_at' => now()->subDay(),
                'stage_history' => [['department' => 'immigration', 'stage' => $stage, 'at' => now()->subDay()->toIso8601String()]]]);
        }
        Lead::create(['first_name' => 'Previous', 'last_name' => 'Week', 'is_immigration_case' => true,
            'immigration_converted_at' => now()->subWeek(), 'immigration_stage' => 'For Assessment']);
        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->get('/portal/immigration/reports')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('range.preset', 'this_week')->where('pipeline.new_clients', 4)
            ->has('namedIntake.new_clients', 4)
            ->where('outcomes.interim.0.name', 'Interim Visa Issued Client')
            ->where('outcomes.rfis.0.name', 'Request for Information Client')
            ->where('outcomes.approved.0.name', 'Approved Visa Client')
            ->where('outcomes.declined.0.name', 'Decline Visa Client'));
    }

    public function test_education_updates_do_not_recount_old_or_undated_immigration_approvals(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-09T00:00:00Z'));
        $educationUpdate = ['department' => 'education', 'stage' => 'Approved Visa', 'at' => '2026-11-07T03:01:46Z'];
        $approved = Lead::create([
            'first_name' => 'Previously', 'last_name' => 'Approved', 'is_immigration_case' => true,
            'immigration_stage' => 'Approved Visa', 'stage_updated_at' => '2026-11-07 03:01:46',
            'stage_history' => [
                ['department' => 'immigration', 'stage' => 'Approved Visa', 'at' => '2026-09-29T00:12:46Z'],
                $educationUpdate,
            ],
        ]);
        Lead::create([
            'first_name' => 'Undated', 'last_name' => 'Approval', 'is_immigration_case' => true,
            'immigration_stage' => 'Approved Visa', 'stage_updated_at' => '2026-11-07 03:01:46',
            'stage_history' => [array_replace($educationUpdate, ['stage' => 'Started Course'])],
        ]);

        $url = '/portal/immigration/reports?preset=custom';
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']))
            ->get($url.'&from=2026-11-02&to=2026-11-08')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('outcomes.approved', 0)->where('activity.visas_approved', 0)->where('conclusion.stats.approved', 0)
                ->where('trend.3.approved', 1)->where('trend.5.approved', 0));
        $this->get($url.'&from=2026-09-29&to=2026-09-29')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('outcomes.approved', 1)->where('outcomes.approved.0.id', $approved->id)
            ->where('activity.visas_approved', 1)->where('conclusion.stats.approved', 1));
    }

    public function test_immigration_history_and_handoffs_use_the_same_nz_reporting_window(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-09T00:00:00Z'));
        $handoff = Lead::create([
            'first_name' => 'Education', 'last_name' => 'Handoff', 'is_immigration_case' => true,
            'education_stage' => 'Endorsed to Immigration', 'immigration_converted_at' => '2026-11-01 11:00:00',
            'stage_history' => [['department' => 'education', 'stage' => 'Endorsed to Immigration', 'at' => '2026-11-01T11:00:00Z']],
        ]);
        $lodged = Lead::create([
            'first_name' => 'Visa', 'last_name' => 'Applicant', 'is_immigration_case' => true,
            'immigration_stage' => 'Approved Visa', 'stage_updated_at' => '2026-11-08 11:00:00',
            'stage_history' => [
                ['department' => 'immigration', 'stage' => 'Visa Lodged', 'at' => '2026-11-01T11:00:00Z'],
                ['department' => 'immigration', 'stage' => 'Request for Information', 'at' => '2026-11-08T10:59:59Z'],
                ['department' => 'immigration', 'stage' => 'Approved Visa', 'at' => '2026-11-08T11:00:00Z'],
            ],
        ]);

        $this->actingAs(User::factory()->create(['role' => 'immigration']));
        foreach (['custom&from=2026-11-02&to=2026-11-08', 'last_week'] as $preset) {
            $this->get('/portal/immigration/reports?preset='.$preset)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('range.from', '2026-11-02')->where('range.to', '2026-11-08')
                ->has('namedIntake.new_clients', 1)->where('namedIntake.new_clients.0.id', $handoff->id)
                ->where('activity.apps_lodged', 1)->where('submissions.lodged.0.id', $lodged->id)
                ->has('outcomes.rfis', 1)->has('outcomes.approved', 0)->where('pipeline.endorsed_lia', 0));
        }
    }

    public function test_external_portal_grants_cannot_read_named_case_reports(): void
    {
        foreach (['lead', 'agent', 'sub_agent', 'revoked_lead', 'sales'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['immigration']]))
                ->get('/portal/immigration/reports')->assertForbidden();
            $this->post('/portal/immigration/reports/note', ['note_key' => 'imm_report_note:test', 'note' => 'forged'])->assertForbidden();
        }
    }

    public function test_adding_an_immigration_pathway_counts_as_intake_without_recounting_an_approval(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-05T00:00:00Z'));
        $student = Lead::create(['first_name' => 'Already', 'last_name' => 'Approved', 'is_student' => true,
            'education_stage' => 'Started Course', 'immigration_stage' => 'Approved Visa']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post("/clients/{$student->id}/pathways", ['pathway' => 'immigration'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/portal/immigration/reports?preset=this_week')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('pipeline.new_clients', 1)->where('namedIntake.new_clients.0.id', $student->id)
            ->has('outcomes.approved', 0));

        $student->refresh();
        $history = $student->stage_history;
        unset($history[0]['pathway_intake']);
        $student->updateQuietly(['stage_history' => $history]);

        $this->get('/portal/immigration/reports?preset=this_week')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('pipeline.new_clients', 1)->has('outcomes.approved', 0));
    }

    public function test_manually_recorded_immigration_case_stages_remain_reportable(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-03T00:00:00Z'));
        $staff = User::factory()->create(['role' => 'immigration']);
        $this->actingAs($staff)->post('/portal/immigration/cases', [
            'first_name' => 'Manual', 'last_name' => 'Case', 'immigration_stage' => 'Visa Lodged', 'visa_type_id' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $case = Lead::where('first_name', 'Manual')->sole();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-04T00:00:00Z'));
        $this->post("/portal/immigration/cases/{$case->id}", [
            'first_name' => 'Manual', 'last_name' => 'Case', 'immigration_stage' => 'Approved Visa', 'visa_type_id' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->get('/portal/immigration/reports?preset=this_week')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('submissions.lodged.0.id', $case->id)->where('submissions.lodged.0.date', '2026-11-03T00:00:00+00:00')
            ->where('outcomes.approved.0.id', $case->id)->where('outcomes.approved.0.date', '2026-11-04T00:00:00+00:00'));
    }

}
