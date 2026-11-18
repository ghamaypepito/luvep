<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The lead-profile header is department-aware: a lead moved to Study edits its
 * education_stage (not the sales `status`), matching the Students list.
 */
class DepartmentStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_education_stage_update_targets_the_right_column(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $lead = Lead::create([
            'first_name' => 'F', 'last_name' => 'O', 'status' => 'English Pro', 'is_student' => true,
        ]);

        $this->post("/admin/leads/{$lead->id}/stage", ['status' => 'School Enrolment', 'field' => 'education_stage'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $lead->fresh();
        $this->assertSame('School Enrolment', $fresh->education_stage);
        $this->assertSame('English Pro', $fresh->status, 'sales status must be untouched');
        $this->assertSame(auth()->id(), $fresh->stage_updated_by);
        $this->assertSame('education', $fresh->stage_history[0]['department']);
        $this->assertSame('School Enrolment', $fresh->stage_history[0]['stage']);
    }

    public function test_sales_status_update_still_works_by_default(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $lead = Lead::create(['first_name' => 'A', 'last_name' => 'L', 'status' => 'New Leads']);

        $this->post("/admin/leads/{$lead->id}/stage", ['status' => 'Proposal Sent'])
            ->assertRedirect();

        $this->assertSame('Proposal Sent', $lead->fresh()->status);
    }

    public function test_invalid_value_for_department_field_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $lead = Lead::create(['first_name' => 'A', 'last_name' => 'L', 'status' => 'New Leads', 'is_student' => true]);

        // "Qualified" is a sales stage, not an education stage.
        $this->post("/admin/leads/{$lead->id}/stage", ['status' => 'Qualified', 'field' => 'education_stage'])
            ->assertSessionHasErrors('status');
    }

    public function test_education_handoff_promotes_to_immigration_and_preserves_the_student(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $lead = Lead::create([
            'first_name' => 'A', 'last_name' => 'L', 'status' => 'New Leads',
            'is_student' => true, 'is_immigration_case' => false,
        ]);

        $this->post("/admin/leads/{$lead->id}/stage", ['status' => 'Endorsed to Immigration', 'field' => 'education_stage'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $lead->fresh();
        $this->assertTrue((bool) $fresh->is_immigration_case);
        $this->assertTrue((bool) $fresh->is_student, 'the student remains in the Education register after handoff');
        $this->assertSame('Endorsed to Immigration', $fresh->education_stage);
        $this->assertNotNull($fresh->immigration_converted_at);
        $this->assertSame(auth()->id(), $fresh->immigration_converted_by);
    }
}
