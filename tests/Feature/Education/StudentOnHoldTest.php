<?php

namespace Tests\Feature\Education;

use App\Models\{Lead, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentOnHoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_hold_and_resume_a_student_with_reportable_history(): void
    {
        $staff = User::factory()->create(['role' => 'education']);
        $student = Lead::create([
            'first_name' => 'Example', 'last_name' => 'Student', 'is_student' => true,
            'education_stage' => 'Proposal Sent', 'stage' => 'New Leads',
        ]);
        $url = "/portal/education/students/{$student->id}/dashboard-field";

        $this->actingAs($staff)->post($url, ['education_stage' => 'On Hold'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertSame('On Hold', $student->education_stage);
        $this->assertSame('New Leads', $student->stage);
        $this->assertTrue((bool) $student->is_student);
        $this->assertFalse((bool) $student->is_immigration_case);
        $this->assertEquals($staff->id, $student->stage_updated_by);
        $this->get('/portal/education/students')->assertOk()->assertInertia(fn ($page) => $page
            ->where('students.0.id', $student->id)
            ->where('students.0.education_stage', 'On Hold'));

        $this->post($url, ['education_stage' => 'Proposal Sent'])->assertSessionHasNoErrors();
        $this->assertSame('Proposal Sent', $student->refresh()->education_stage);

        $this->get('/portal/education/reports?preset=today')->assertOk()->assertInertia(fn ($page) => $page
            ->where('departments.education.stages', fn ($stages) => collect($stages)->contains(fn ($stage) =>
                $stage['stage'] === 'On Hold' && array_column($stage['clients'], 'id') === [$student->id])));
    }
}
