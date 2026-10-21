<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProposalVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function program(string $t): Program
    {
        return Program::create(['title' => $t, 'level' => '7', 'category' => 'bachelors', 'status' => 'published', 'location' => 'Auckland']);
    }

    public function test_submitting_a_proposal_marks_it_pending_and_hides_it_from_the_tracker(): void
    {
        Mail::fake();
        $staff = User::factory()->create(['role' => 'admin']);
        $lead = Lead::create(['first_name' => 'A', 'last_name' => 'L', 'email' => 'a@x.com', 'tracking_code' => 'PV111']);
        $p = $this->program('Bachelor of Accounting');

        $this->actingAs($staff)->post("/admin/leads/{$lead->id}/proposal", ['program_ids' => [$p->id]])->assertRedirect();

        $this->assertSame('pending', $lead->fresh()->proposalStatus());
        $this->assertFalse($lead->fresh()->proposalIsLive());

        // Tracker hides a pending proposal.
        $this->get('/track/PV111')
            ->assertOk()
            ->assertInertia(fn ($pg) => $pg->where('proposal', null));

        // No proposal email fired on submit.
        Mail::assertNothingSent();
    }

    public function test_verify_then_approve_makes_it_live_and_emails(): void
    {
        Mail::fake();
        $super = User::factory()->create(['role' => 'super_admin']);
        $staff = User::factory()->create(['role' => 'admin']);
        $lead = Lead::create(['first_name' => 'A', 'last_name' => 'L', 'email' => 'a@x.com', 'tracking_code' => 'PV222']);
        $p = $this->program('Bachelor of Accounting');
        $this->actingAs($staff)->post("/admin/leads/{$lead->id}/proposal", ['program_ids' => [$p->id]]);

        // Two-step: verify, then approve.
        $this->actingAs($super)->post("/program-verification/{$lead->id}/verify")->assertRedirect();
        $this->assertSame('verified', $lead->fresh()->proposalStatus());

        $this->actingAs($super)->post("/program-verification/{$lead->id}/approve")->assertRedirect();
        $this->assertSame('approved', $lead->fresh()->proposalStatus());
        $this->assertTrue($lead->fresh()->proposalIsLive());

        // Now the tracker shows it.
        $this->get('/track/PV222')
            ->assertOk()
            ->assertInertia(fn ($pg) => $pg->where('proposal.programs.0.id', $p->id));
    }

    public function test_dinah_can_edit_the_shortlist(): void
    {
        $dinah = User::factory()->create(['role' => 'education', 'module_permissions' => ['program_verification']]);
        $staff = User::factory()->create(['role' => 'admin']);
        $lead = Lead::create(['first_name' => 'A', 'last_name' => 'L', 'tracking_code' => 'PVE1']);
        $p1 = $this->program('Original');
        $p2 = $this->program('Swapped In');
        $this->actingAs($staff)->post("/admin/leads/{$lead->id}/proposal", ['program_ids' => [$p1->id]]);

        // Swap p1 → p2 and add a reason.
        $this->actingAs($dinah)->post("/program-verification/{$lead->id}/programs", [
            'program_ids' => [$p2->id],
            'reasons' => [(string) $p2->id => 'Better fit.'],
        ])->assertRedirect();

        $lead->refresh();
        $this->assertSame([$p2->id], $lead->proposed_program_ids);
        $this->assertSame('Better fit.', $lead->proposed_program_reasons[(string) $p2->id]);
        // Still under review.
        $this->assertSame('pending', $lead->proposalStatus());
    }

    public function test_education_reviewer_with_proposal_grant_can_approve_from_own_portal(): void
    {
        Mail::fake();
        $reviewer = User::factory()->create([
            'role' => 'education',
            'module_permissions' => ['program_verification.proposal'],
        ]);
        $program = $this->program('Bachelor of Accounting');
        $lead = Lead::create([
            'first_name' => 'Proposal', 'last_name' => 'Applicant',
            'email' => 'proposal@example.test',
            'proposed_program_ids' => [$program->id],
            'proposal_review' => ['status' => 'pending'],
        ]);

        $this->actingAs($reviewer)->get('/program-verification')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portal/education/ProgramVerification')
                ->where('leadBase', '/portal/education')
                ->where('proposals.0.id', $lead->id));

        $this->from('/program-verification')->post("/program-verification/{$lead->id}/approve", [
            'verify_all' => true, 'send_email' => false,
        ])->assertRedirect('/program-verification');

        $lead->refresh();
        $this->assertSame('approved', $lead->proposalStatus());
        $this->assertTrue($lead->proposalIsLive());
        $this->assertSame($reviewer->id, $lead->proposal_review['verified_by']);
        $this->assertSame($reviewer->id, $lead->proposal_review['approved_by']);
        $this->assertSame('verified', $lead->proposed_program_meta[$program->id]['status']);
        $this->assertTrue($lead->proposed_program_meta[$program->id]['fee_confirmed']);
        Mail::assertNothingSent();
    }

    public function test_education_staff_without_proposal_grant_cannot_verify_or_approve(): void
    {
        Mail::fake();
        $program = $this->program('Bachelor of Accounting');
        $lead = Lead::create([
            'first_name' => 'Proposal', 'last_name' => 'Applicant',
            'proposed_program_ids' => [$program->id],
            'proposal_review' => ['status' => 'pending'],
        ]);

        foreach ([[], ['program_verification.consultancy']] as $grants) {
            $staff = User::factory()->create(['role' => 'education', 'module_permissions' => $grants]);
            $this->actingAs($staff)->post("/program-verification/{$lead->id}/verify")->assertForbidden();
            $this->post("/program-verification/{$lead->id}/approve", [
                'verify_all' => true, 'send_email' => false,
            ])->assertForbidden();
            $this->assertSame(['status' => 'pending'], $lead->fresh()->proposal_review);
        }
        Mail::assertNothingSent();
    }

    public function test_module_is_gated(): void
    {
        $this->program('X');
        // Ungranted education staff cannot reach it.
        $edu = User::factory()->create(['role' => 'education']);
        $this->actingAs($edu)->get('/program-verification')->assertForbidden();

        // Granted education staff (Dinah) can.
        $dinah = User::factory()->create(['role' => 'education', 'module_permissions' => ['program_verification']]);
        $this->actingAs($dinah)->get('/program-verification')->assertOk();

        // Super admin always can.
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))->get('/program-verification')->assertOk();
    }
}
