<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Program Verification module — the enriched review queue + per-program
 * verification actions (fee / school / status), verify and approve.
 */
class ProgramVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function reviewer(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function proposalLead(array $programIds): Lead
    {
        return Lead::create([
            'first_name' => 'Emma', 'last_name' => 'Thompson',
            'email' => 'emma@example.com',
            'proposed_program_ids' => $programIds,
            'proposal_review' => ['status' => 'pending', 'submitted_at' => now()->toIso8601String()],
        ]);
    }

    public function test_approved_history_includes_older_records_and_paginates(): void
    {
        $this->withoutVite();
        for ($i = 0; $i < 51; $i++) {
            $lead = $this->proposalLead([]);
            $lead->update(['proposal_review' => ['status' => 'approved', 'approved_at' => now()->subDays(30)->toIso8601String()]]);
        }
        $this->actingAs($this->reviewer())->get('/program-verification?status=approved')
            ->assertOk()->assertInertia(fn (Assert $p) => $p->has('proposals', 50)->where('counts.approved', 51)->where('counts.approved_today', 0)->where('pagination.total', 51)->where('selectedStatus', 'approved'));
        $this->get('/program-verification?status=approved&page=2')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('proposals', 1)->where('pagination.current_page', 2));
        $this->get('/program-verification')->assertOk()->assertInertia(fn (Assert $p) => $p->has('proposals', 0)->where('counts.approved', 51));
    }

    public function test_index_returns_enriched_queue_with_counts_and_programs(): void
    {
        $prog = Program::create(['title' => 'Bachelor of Applied Management', 'level' => 7, 'category' => 'bachelors', 'status' => 'published', 'institution' => 'Wintec', 'intake_months' => 'Feb 2027', 'tuition_fee' => 24500]);
        $this->proposalLead([$prog->id]);

        $this->actingAs($this->reviewer())
            ->get('/program-verification')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('admin/ProgramVerification')
                ->where('counts.pending', 1)
                ->has('proposals.0', fn (Assert $row) => $row
                    ->where('name', 'Emma Thompson')
                    ->where('initials', 'ET')
                    ->where('programs_count', 1)
                    ->has('checks')
                    ->has('programs.0', fn (Assert $pr) => $pr
                        ->where('school', 'Wintec')
                        ->where('intake', 'Feb 2027')
                        ->where('fee', 24500)
                        ->where('p_status', 'needs_check')
                        ->etc()
                    )
                    ->etc()
                )
                ->has('schools')
            );
    }

    public function test_update_program_meta_sets_fee_school_and_marks_verified(): void
    {
        $prog = Program::create(['title' => 'Prog', 'level' => 7, 'category' => 'bachelors', 'status' => 'published']);
        $lead = $this->proposalLead([$prog->id]);

        $this->actingAs($this->reviewer())
            ->post("/program-verification/{$lead->id}/programs-meta", [
                'meta' => [(string) $prog->id => ['fee' => 26000, 'school' => 'AUT', 'status' => 'verified']],
            ])->assertRedirect();

        $meta = $lead->refresh()->proposed_program_meta[(string) $prog->id];
        $this->assertSame(26000, (int) $meta['fee']);
        $this->assertSame('AUT', $meta['school']);
        $this->assertSame('verified', $meta['status']);
        $this->assertTrue($meta['fee_confirmed']);
        $this->assertTrue($meta['edited']); // fee/school change flags the row edited
    }

    public function test_verify_then_approve_without_email_makes_it_live(): void
    {
        $prog = Program::create(['title' => 'Prog', 'level' => 7, 'category' => 'bachelors', 'status' => 'published']);
        $lead = $this->proposalLead([$prog->id]);
        $reviewer = $this->reviewer();

        $this->actingAs($reviewer)->post("/program-verification/{$lead->id}/verify")->assertRedirect();
        $this->assertSame('verified', $lead->refresh()->proposal_review['status']);

        $this->actingAs($reviewer)->post("/program-verification/{$lead->id}/approve", ['send_email' => 0])->assertRedirect();
        $review = $lead->refresh()->proposal_review;
        $this->assertSame('approved', $review['status']);
        $this->assertFalse($review['emailed']);
    }

    public function test_verify_and_approve_all_confirms_every_program(): void
    {
        $a = Program::create(['title' => 'A', 'level' => 7, 'category' => 'bachelors', 'status' => 'published']);
        $b = Program::create(['title' => 'B', 'level' => 7, 'category' => 'bachelors', 'status' => 'published']);
        $lead = $this->proposalLead([$a->id, $b->id]);

        $this->actingAs($this->reviewer())
            ->post("/program-verification/{$lead->id}/approve", ['verify_all' => 1, 'send_email' => 0])
            ->assertRedirect();

        $lead->refresh();
        $this->assertSame('approved', $lead->proposal_review['status']);
        foreach ([$a->id, $b->id] as $id) {
            $this->assertSame('verified', $lead->proposed_program_meta[(string) $id]['status']);
            $this->assertTrue($lead->proposed_program_meta[(string) $id]['fee_confirmed']);
        }
    }

    public function test_verification_updates_surface_on_proposals_agreements_page(): void
    {
        $prog = Program::create(['title' => 'Prog', 'level' => 7, 'category' => 'bachelors', 'status' => 'published']);
        $lead = $this->proposalLead([$prog->id]);
        $reviewer = $this->reviewer();

        // Reviewer sets an internal note + verifies the program, and requests changes.
        $this->actingAs($reviewer)->post("/program-verification/{$lead->id}/programs-meta", [
            'meta' => [(string) $prog->id => ['note' => 'Check the intake with AUT', 'school' => 'AUT', 'status' => 'verified']],
        ])->assertRedirect();
        $this->actingAs($reviewer)->post("/program-verification/{$lead->id}/request-changes", [
            'message' => 'Confirm the fee', 'program_ids' => [$prog->id],
        ])->assertRedirect();

        $this->actingAs($reviewer)
            ->get('/admin/leads/proposals-agreements')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('proposals', fn (Assert $list) => $list
                    ->where('0.changes_requested.message', 'Confirm the fee')
                    ->where('0.changes_requested.program_ids.0', $prog->id)
                    ->has('0.programs.0', fn (Assert $pr) => $pr
                        ->where('note', 'Check the intake with AUT')
                        ->where('school', 'AUT')
                        ->where('verify_status', 'verified')
                        ->etc()
                    )
                    ->etc()
                )
                ->etc()
            );
    }

    public function test_resubmit_sends_the_proposal_back_to_pending_and_clears_changes(): void
    {
        $prog = Program::create(['title' => 'Prog', 'level' => 7, 'category' => 'bachelors', 'status' => 'published']);
        $lead = Lead::create([
            'first_name' => 'Sofia', 'last_name' => 'Rossi',
            'proposed_program_ids' => [$prog->id],
            'proposal_review' => ['status' => 'verified', 'changes_requested' => ['message' => 'old']],
        ]);

        $this->actingAs($this->reviewer())
            ->post("/admin/leads/{$lead->id}/proposal/resubmit")
            ->assertRedirect();

        $review = $lead->refresh()->proposal_review;
        $this->assertSame('pending', $review['status']);
        $this->assertArrayNotHasKey('changes_requested', $review);
    }

    public function test_request_changes_flags_the_proposal_and_specific_programs(): void
    {
        $a = Program::create(['title' => 'A', 'level' => 7, 'category' => 'bachelors', 'status' => 'published']);
        $b = Program::create(['title' => 'B', 'level' => 7, 'category' => 'bachelors', 'status' => 'published']);
        $lead = $this->proposalLead([$a->id, $b->id]);

        $this->actingAs($this->reviewer())
            ->post("/program-verification/{$lead->id}/request-changes", [
                'message' => 'Fix the fee',
                'program_ids' => [$a->id, 999], // 999 is not on the shortlist → dropped
            ])
            ->assertRedirect();

        $review = $lead->refresh()->proposal_review;
        $this->assertSame('pending', $review['status']);
        $this->assertSame('Fix the fee', $review['changes_requested']['message']);
        $this->assertSame([$a->id], $review['changes_requested']['program_ids']);
    }
}
