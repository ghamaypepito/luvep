<?php

namespace Tests\Feature\Education;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EducationReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_mirrors_the_leads_list_by_sales_status(): void
    {
        $edu = User::factory()->create(['role' => 'education']);

        // Section 01 is the LEADS pipeline: a pipeline lead is counted under its
        // real sales status. Only occupied statuses are shown.
        Lead::create([
            'first_name' => 'Isha', 'last_name' => 'Basin', 'email' => 'i@x.com',
            'tracking_code' => 'ER2', 'status' => 'Proposal Sent', 'stage_updated_at' => now(),
        ]);

        $this->actingAs($edu)->get('/portal/education/reports?preset=this_month')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('portal/education/Reports')
                ->where('range.preset', 'this_month')
                ->where('pipeline.0.stage', 'Proposal Sent')
                ->where('pipeline.0.count', 1)
                ->where('totalRegister', 1)
                ->has('register.Proposal Sent', 1)
                ->has('conclusion.note_key')
            );
    }

    public function test_education_created_lead_reflects_in_the_pipeline(): void
    {
        $edu = User::factory()->create(['role' => 'education']);

        $this->actingAs($edu)->post('/portal/education/leads', [
            'first_name' => 'Anil', 'last_name' => 'Sharma', 'email' => 'anil@x.com', 'status' => 'New Leads',
        ])->assertRedirect();

        $this->assertNotNull(Lead::where('first_name', 'Anil')->first());

        // It shows under its "New Leads" sales status immediately — no conversion
        // needed, because Section 01 mirrors the Leads list.
        $this->actingAs($edu)->get('/portal/education/reports?preset=this_month')
            ->assertInertia(fn (Assert $p) => $p
                ->where('pipeline.0.stage', 'New Leads')
                ->where('pipeline.0.count', 1)
                ->has('register.New Leads', 1)
            );
    }

    public function test_a_converted_student_leaves_the_leads_pipeline(): void
    {
        $edu = User::factory()->create(['role' => 'education']);

        // A converted student is off the leads pipeline and only in the
        // Education department breakdown.
        Lead::create([
            'first_name' => 'Leyven', 'last_name' => 'Padera', 'email' => 'l@x.com',
            'tracking_code' => 'ER1', 'is_student' => true,
            'education_stage' => 'Started Course', 'stage_updated_at' => now(),
            'student_converted_at' => now(),
        ]);

        $this->actingAs($edu)->get('/portal/education/reports?preset=this_month')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('totalRegister', 0)          // not in the leads pipeline
                ->where('departments.education.total', 1)
                ->where('departments.education.stages', fn ($stages) => collect($stages)
                    ->firstWhere('stage', 'Started Course')['count'] === 1)
            );
    }

    public function test_english_and_immigration_students_are_tracked_under_their_own_statuses(): void
    {
        $edu = User::factory()->create(['role' => 'education']);

        // An English learner mid-prep — tracked under the English department at
        // its own real status "For PTE Exam", NOT folded onto an education stage.
        Lead::create([
            'first_name' => 'Mei', 'last_name' => 'Lin', 'email' => 'mei@x.com',
            'tracking_code' => 'ER3', 'english_stage' => 'For PTE Exam', 'stage_updated_at' => now(),
        ]);

        // An education student handed to Immigration remains in the Students
        // register, tracked at its own "Request for Information" status.
        Lead::create([
            'first_name' => 'Raj', 'last_name' => 'Patel', 'email' => 'raj@x.com',
            'tracking_code' => 'ER4', 'is_student' => true,
            'is_immigration_case' => true, 'inz_visa_type' => 'Student Visa',
            'immigration_stage' => 'Request for Information', 'stage_updated_at' => now(),
        ]);

        $this->actingAs($edu)->get('/portal/education/reports?preset=this_month')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('departments.english.total', 1)
                ->where('departments.immigration.total', 1)
                // Neither pollutes the education pipeline.
                ->where('totalRegister', 0)
                // English learner sits under "For PTE Exam".
                ->where('departments.english.stages', fn ($stages) => collect($stages)
                    ->firstWhere('stage', 'For PTE Exam')['count'] === 1)
                // Immigration case sits under "Request for Information".
                ->where('departments.immigration.stages', fn ($stages) => collect($stages)
                    ->firstWhere('stage', 'Request for Information')['count'] === 1)
            );
    }

    public function test_a_pure_immigration_case_is_excluded_from_the_students_report(): void
    {
        $edu = User::factory()->create(['role' => 'education']);
        Lead::create([
            'first_name' => 'Visa', 'last_name' => 'Applicant', 'email' => 'visa@x.com',
            'is_immigration_case' => true, 'inz_visa_type' => 'Student Visa',
            'immigration_stage' => 'Request for Information', 'stage_updated_at' => now(),
        ]);

        $this->actingAs($edu)->get('/portal/education/reports?preset=this_month')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('totalRegister', 0)
                ->where('departments.education.total', 0)
                ->where('departments.immigration.total', 0)
            );
    }

    public function test_report_note_can_be_saved(): void
    {
        $edu = User::factory()->create(['role' => 'education']);
        $key = 'edu_report_note:2026-09-01:2026-09-14';

        $this->actingAs($edu)->post('/portal/education/reports/note', [
            'note_key' => $key, 'note' => 'Follow up the proposals.',
        ])->assertRedirect();

        $this->assertSame('Follow up the proposals.', \App\Models\Setting::get($key));
    }

    public function test_report_note_rejects_a_foreign_key(): void
    {
        $edu = User::factory()->create(['role' => 'education']);
        $this->actingAs($edu)->post('/portal/education/reports/note', [
            'note_key' => 'imm_report_note:x:y', 'note' => 'nope',
        ])->assertSessionHasErrors('note_key');
    }
}
