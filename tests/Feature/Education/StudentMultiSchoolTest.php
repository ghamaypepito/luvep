<?php

namespace Tests\Feature\Education;

use App\Models\Lead;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentMultiSchoolTest extends TestCase
{
    use RefreshDatabase;

    private function base(array $over = []): array
    {
        return array_merge([
            'first_name' => 'Marife', 'last_name' => 'Bayhon',
            'email' => 'm@example.com', 'phone' => '+63 998 430 0820',
            'department' => 'education', 'education_stage' => Lead::EDUCATION_STAGES[0],
        ], $over);
    }

    public function test_creates_a_student_with_multiple_schools(): void
    {
        $edu = User::factory()->create(['role' => 'education']);
        $icl = School::create(['name' => 'ICL Graduate Business School']);

        $this->actingAs($edu)->post('/portal/education/students', $this->base([
            'program_text' => 'Master of Business Informatics by Thesis · Bachelor of Accountancy',
            'school_text' => 'ICL Graduate Business School · Southern Institute of Technology',
            'school_id' => $icl->id,
        ]))->assertRedirect();

        $lead = Lead::where('email', 'm@example.com')->first();
        $this->assertNotNull($lead);
        // Full multi-school list preserved verbatim.
        $this->assertSame('ICL Graduate Business School · Southern Institute of Technology', $lead->student_school);
        // Legacy single FK keeps the first matched school.
        $this->assertSame($icl->id, $lead->school_id);
    }

    public function test_stores_per_program_schools_on_the_study_plan(): void
    {
        $edu = User::factory()->create(['role' => 'education']);

        $this->actingAs($edu)->post('/portal/education/students', $this->base([
            'program_text' => 'Master of Business Informatics by Thesis · Bachelor of Applied Hotel Management',
            // Index-aligned with program_text.
            'program_schools' => ['Massey University', 'ATMC New Zealand'],
            'school_text' => 'Massey University · ATMC New Zealand',
        ]))->assertRedirect();

        $plan = \App\Models\Lead::where('email', 'm@example.com')->first()->studyPlans->first();
        $this->assertSame(['Massey University', 'ATMC New Zealand'], $plan->program_schools);
    }

    public function test_update_replaces_the_school_list(): void
    {
        $edu = User::factory()->create(['role' => 'education']);
        $lead = Lead::create([
            'first_name' => 'Marife', 'last_name' => 'Bayhon', 'email' => 'm@example.com',
            'phone' => '+63 998 430 0820', 'tracking_code' => 'SMS1', 'is_student' => true,
            'student_school' => 'Old School',
        ]);

        $this->actingAs($edu)->post("/portal/education/students/{$lead->id}/update", $this->base([
            'school_text' => 'New School A · New School B',
        ]))->assertRedirect()->assertSessionMissing('error');

        $this->assertSame('New School A · New School B', $lead->fresh()->student_school);
        $this->assertFalse($lead->studyPlans()->exists());
    }
}
