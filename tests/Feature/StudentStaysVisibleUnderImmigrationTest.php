<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A student the Education team hands off to Immigration is legitimately under
 * BOTH departments — they must stay a student (visible on the Education tab of
 * the Students screen) as well as becoming an immigration case. Previously the
 * handoff cleared is_student, dropping them off Education entirely.
 */
class StudentStaysVisibleUnderImmigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_education_handoff_keeps_is_student_true(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));

        $lead = Lead::create([
            'first_name' => 'Dual', 'last_name' => 'Student', 'email' => 'dual@example.com',
            'is_student' => true, 'student_converted_at' => now(),
        ]);

        // Move to an Education stage that hands the case to Immigration.
        $this->post("/portal/education/students/{$lead->id}/dashboard-field", [
            'education_stage' => 'Endorsed to Immigration',
        ])->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertTrue((bool) $lead->is_immigration_case, 'promoted to immigration case');
        $this->assertTrue((bool) $lead->is_student, 'still a student — stays visible under Education');
        $this->assertSame('Student Visa', $lead->inz_visa_type);
    }

    public function test_handoff_preserves_an_existing_visa_and_denies_unrelated_portal_roles(): void
    {
        $lead = Lead::create(['first_name' => 'Existing', 'last_name' => 'Visa', 'is_student' => true, 'inz_visa_type' => 'Partner Visa']);
        foreach (['lead', 'agent'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post("/portal/education/students/{$lead->id}/dashboard-field", ['education_stage' => 'Endorsed to Immigration'])
                ->assertForbidden();
        }
        $this->assertFalse($lead->fresh()->is_immigration_case);
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->post("/portal/education/students/{$lead->id}/dashboard-field", ['education_stage' => 'Endorsed to Immigration'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Partner Visa', $lead->fresh()->inz_visa_type);
    }

    public function test_dual_lead_is_returned_by_the_students_query(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));

        Lead::create([
            'first_name' => 'Dual', 'last_name' => 'Both', 'email' => 'both@example.com',
            'is_student' => true, 'is_immigration_case' => true,
            'immigration_stage' => 'Endorsed', 'student_converted_at' => now(),
        ]);

        // The Students screen data includes leads that are students OR cases;
        // the frontend then shows a dual lead under both tabs.
        $res = $this->get('/portal/education/students')->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->where('students', fn ($students) => collect($students)->contains(
                fn ($s) => $s['email'] === 'both@example.com' && $s['is_student'] && $s['is_immigration_case']
            ))
        );
    }
}
