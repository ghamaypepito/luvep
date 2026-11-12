<?php

namespace Tests\Feature\Immigration;

use App\Models\Assessment;
use App\Models\Lead;
use App\Models\PreScreening;
use App\Models\User;
use App\Models\VisaType;
use App\Models\WorkIntake;
use App\Services\Immigration\AssessmentCaseLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AssessmentCaseVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
    }

    private function submission(): array
    {
        $visa = VisaType::create(['code' => 'WORK_AEWV', 'name' => 'Accredited Employer Work Visa']);
        $intake = WorkIntake::create(['intake_id' => 'WORK-SYNTHETIC', 'first_name' => 'Synthetic',
            'family_name' => 'Applicant', 'email' => 'synthetic@example.test', 'dob' => '1990-01-01', 'status' => 'Engaged']);
        $assessment = Assessment::create(['visa_type_id' => $visa->id, 'intakeable_type' => WorkIntake::class,
            'intakeable_id' => $intake->id, 'applicant_first_name' => $intake->first_name,
            'applicant_last_name' => $intake->family_name, 'applicant_email' => $intake->email, 'status' => 'completed']);
        return [$intake, $assessment];
    }

    private function row(WorkIntake $intake): array
    {
        $response = $this->get('/portal/immigration/assessments');
        $response->assertOk();
        return collect($response->inertiaProps('intakes'))->first(fn ($r) => $r['record'] === 'intake'
            && $r['visa_type'] === 'work' && $r['id'] === $intake->id);
    }

    public function test_stale_engaged_status_is_not_conversion_proof_and_retry_reuses_working_lead(): void
    {
        [$intake, $assessment] = $this->submission();
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Applicant', 'email' => $intake->email]);
        PreScreening::create(['subject_type' => WorkIntake::class, 'subject_id' => $intake->id,
            'lead_id' => $lead->id, 'status' => PreScreening::STATUS_CONVERTED]);
        $row = $this->row($intake);
        $this->assertFalse($row['journey']['converted']);
        $this->assertTrue($row['can_convert']);
        $this->assertNull($row['case_url']);
        $this->assertNotEmpty($row['conversion_issue']);
        $this->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case",
            ['intake_type' => 'work', 'intake_id' => $intake->id])
            ->assertRedirect("/portal/immigration/cases/{$lead->id}/profile?tab=documents")
            ->assertSessionHas('success');
        $this->assertSame(1, Lead::count());
        $this->assertTrue($lead->fresh()->is_immigration_case);
        $row = $this->row($intake);
        $this->assertTrue($row['journey']['converted']);
        $this->assertSame($lead->id, $row['case_id']);
        $this->get('/portal/immigration/cases')->assertOk()->assertInertia(fn ($p) => $p
            ->where('cases.0.id', $lead->id)->where('cases.0.name', 'Synthetic Applicant'));
    }

    public function test_exact_assessment_case_is_linked_and_reused_even_after_name_change(): void
    {
        [$intake, $assessment] = $this->submission();
        $lead = Lead::create(['first_name' => 'Corrected', 'last_name' => 'Name', 'email' => 'changed@example.test',
            'assessment_id' => $assessment->id, 'is_immigration_case' => true, 'immigration_converted_at' => now()->subDay()]);
        $stamp = $lead->immigration_converted_at;
        $row = $this->row($intake);
        $this->assertSame($lead->id, $row['case_id']);
        $this->assertFalse($row['can_convert']);
        $this->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case",
            ['intake_type' => 'work', 'intake_id' => $intake->id])->assertRedirect($row['case_url']);
        $this->assertSame(1, Lead::count());
        $this->assertTrue($lead->fresh()->immigration_converted_at->equalTo($stamp));
    }

    public function test_deleted_original_is_not_reported_live_or_recreated_by_retry(): void
    {
        [$intake, $assessment] = $this->submission();
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Applicant', 'assessment_id' => $assessment->id, 'is_immigration_case' => true]);
        $lead->delete();
        $row = $this->row($intake);
        $this->assertFalse($row['journey']['converted']);
        $this->assertFalse($row['can_convert']);
        $this->assertTrue($row['conversion_blocked']);
        $this->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case",
            ['intake_type' => 'work', 'intake_id' => $intake->id])->assertSessionHas('error');
        $this->assertSame(0, Lead::count());
        $this->assertSame(1, Lead::withTrashed()->count());
    }

    public function test_shared_email_does_not_link_a_different_family_member(): void
    {
        [$intake, $assessment] = $this->submission();
        Lead::create(['first_name' => 'Relative', 'last_name' => 'Applicant', 'email' => $intake->email, 'dob' => '1995-01-01', 'is_immigration_case' => true]);
        $link = (new AssessmentCaseLink)->resolve($intake, $assessment);
        $this->assertNull($link['case_id']);
        $this->assertFalse($link['conversion_blocked']);
    }

    public function test_ambiguous_identity_is_not_silently_linked_or_converted(): void
    {
        [$intake, $assessment] = $this->submission();
        foreach (['One', 'Two'] as $name) {
            Lead::create(['first_name' => $name, 'last_name' => 'Applicant', 'email' => $intake->email, 'dob' => $intake->dob, 'is_immigration_case' => true]);
        }
        $row = $this->row($intake);
        $this->assertNull($row['case_url']);
        $this->assertFalse($row['can_convert']);
        $this->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case",
            ['intake_type' => 'work', 'intake_id' => $intake->id])->assertSessionHas('error');
        $this->assertSame(2, Lead::count());
    }

    public function test_resident_without_assessment_reuses_its_working_lead_and_remains_findable(): void
    {
        $intake = \App\Models\ResidentIntake::create(['intake_id' => 'RES-SYNTHETIC', 'first_name' => 'Synthetic',
            'last_name' => 'Resident', 'email' => 'resident@example.test', 'status' => 'Engaged']);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Resident', 'email' => $intake->email]);
        PreScreening::create(['subject_type' => $intake->getMorphClass(), 'subject_id' => $intake->id,
            'lead_id' => $lead->id, 'status' => PreScreening::STATUS_CONVERTED]);
        $this->post("/portal/immigration/assessments/{$intake->id}/convert-to-case",
            ['intake_type' => 'resident', 'intake_id' => $intake->id])->assertRedirect(
                "/portal/immigration/cases/{$lead->id}/profile?tab=documents");
        $this->assertSame(1, Lead::count());
        $link = (new AssessmentCaseLink)->resolve($intake->fresh(), null);
        $this->assertSame($lead->id, $link['case_id']);
        $this->assertFalse($link['conversion_blocked']);
    }

    public function test_finance_cannot_bypass_immigration_conversion_permissions(): void
    {
        [$intake, $assessment] = $this->submission();
        $this->actingAs(User::factory()->create(['role' => 'finance']))
            ->post("/portal/immigration/assessments/{$assessment->id}/convert-to-case",
                ['intake_type' => 'work', 'intake_id' => $intake->id])->assertForbidden();
        $this->assertSame(0, Lead::count());
    }

    public function test_recorded_conversion_target_remains_linked_without_a_working_lead_or_matching_identity(): void
    {
        [$intake, $assessment] = $this->submission();
        $lead = Lead::create(['first_name' => 'Corrected', 'last_name' => 'Identity',
            'email' => 'corrected@example.test', 'is_immigration_case' => true]);
        PreScreening::create(['subject_type' => WorkIntake::class, 'subject_id' => $intake->id,
            'converted_lead_id' => $lead->id, 'status' => PreScreening::STATUS_CONVERTED]);
        $row = $this->row($intake);
        $this->assertSame($lead->id, $row['case_id']);
        $this->assertTrue($row['journey']['converted']);
        $this->assertFalse($row['can_convert']);
    }
}
