<?php

namespace Tests\Feature\Education;

use App\Models\{Lead, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentCourseStartTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return array_merge(['first_name' => 'Course', 'last_name' => 'Student', 'email' => 'course@example.test', 'phone' => '+639000000000', 'education_stage' => 'Started Course', 'program_text' => ''], $extra);
    }

    public function test_create_keeps_planned_intake_and_actual_start_separate(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->post('/portal/education/students', $this->payload(['intake' => '2026-09-01', 'actual_course_started_on' => '2026-09-03']))
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('error');
        $lead = Lead::where('email', 'course@example.test')->firstOrFail();
        $this->assertSame('Started Course', $lead->education_stage);
        $this->assertSame('2026-09-03', $lead->education_notes['actual_course_started_on'] ?? null);
        $this->assertSame('2026-09-01', $lead->studyPlans()->first()->preferred_intake);
        $this->get('/portal/education/students')->assertOk()->assertInertia(fn ($page) => $page
            ->where('students.0.actual_course_started_on', '2026-09-03')
            ->where('students.0.intake', '2026-09-01'));
    }

    public function test_edit_preserves_other_notes_and_omitted_date_but_allows_clear(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        $notes = ['education_docs' => ['Transcript'], 'uploaded_files' => ['cv' => ['synthetic.pdf']], 'actual_course_started_on' => '2026-09-03'];
        $lead = Lead::create(['first_name' => 'Course', 'last_name' => 'Student', 'is_student' => true, 'education_stage' => 'Started Course', 'education_notes' => $notes]);
        $plan = $lead->studyPlans()->create(['qualification_level' => '', 'preferred_intake' => '2026-09-01']);
        $url = "/portal/sales/students/{$lead->id}/update";
        $this->post($url, $this->payload())->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame($notes, $lead->refresh()->education_notes);
        $this->assertSame('2026-09-01', $plan->refresh()->preferred_intake);
        $this->post($url, $this->payload(['actual_course_started_on' => '2026-09-04']))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $notes['actual_course_started_on'] = '2026-09-04';
        $this->assertSame($notes, $lead->refresh()->education_notes);
        $this->assertSame('2026-09-01', $plan->refresh()->preferred_intake);
        $this->post($url, $this->payload(['actual_course_started_on' => '']))->assertSessionHasNoErrors()->assertSessionMissing('error');
        $notes['actual_course_started_on'] = null;
        $this->assertSame($notes, $lead->refresh()->education_notes);
        $this->assertSame('2026-09-01', $plan->refresh()->preferred_intake);
        $this->assertSame('Started Course', $lead->education_stage);
    }

    public function test_stage_change_does_not_fabricate_a_start_date(): void
    {
        $lead = Lead::create(['first_name' => 'Course', 'last_name' => 'Student', 'is_student' => true]);
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->post("/portal/education/students/{$lead->id}/dashboard-field", ['education_stage' => 'Started Course'])->assertSessionHasNoErrors();
        $this->assertSame('Started Course', $lead->refresh()->education_stage);
        $this->assertNull($lead->education_notes['actual_course_started_on'] ?? null);
    }

    public function test_existing_person_is_linked_without_losing_notes_or_changing_stage(): void
    {
        $lead = Lead::create(['first_name' => 'Course', 'last_name' => 'Student', 'education_notes' => ['education_docs' => ['CV']]]);
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->post('/portal/education/students/link-existing', $this->payload(['lead_id' => $lead->id, 'education_stage' => 'School Enrolment', 'actual_course_started_on' => '2026-09-04']))
            ->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame(['education_docs' => ['CV'], 'actual_course_started_on' => '2026-09-04'], $lead->refresh()->education_notes);
        $this->assertSame('School Enrolment', $lead->education_stage);
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_invalid_date_and_unauthorized_write_are_rejected(): void
    {
        $lead = Lead::create(['first_name' => 'Course', 'last_name' => 'Student', 'is_student' => true]);
        $url = "/portal/education/students/{$lead->id}/update";
        $this->actingAs(User::factory()->create(['role' => 'education']))->post($url, $this->payload(['actual_course_started_on' => '2026-02-30']))->assertSessionHasErrors('actual_course_started_on');
        $this->actingAs(User::factory()->create(['role' => 'agent']))->post($url, $this->payload(['actual_course_started_on' => '2026-09-04']))->assertForbidden();
        $this->assertNull($lead->refresh()->education_notes);
    }
}
