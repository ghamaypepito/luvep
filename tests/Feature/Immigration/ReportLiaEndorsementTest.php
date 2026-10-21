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
                'immigration_stage' => $stage, 'immigration_converted_at' => now()->subDay(), 'stage_updated_at' => now()->subDay()]);
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
    public function test_external_portal_grants_cannot_read_named_case_reports(): void
    {
        foreach (['lead', 'agent', 'sub_agent', 'revoked_lead', 'sales'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['immigration']]))
                ->get('/portal/immigration/reports')->assertForbidden();
            $this->post('/portal/immigration/reports/note', ['note_key' => 'imm_report_note:test', 'note' => 'forged'])->assertForbidden();
        }
    }

}
