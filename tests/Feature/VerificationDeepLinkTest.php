<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VerificationDeepLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_education_reviewer_can_open_exact_proposal_outside_first_page(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['role' => 'education', 'module_permissions' => ['program_verification.proposal']]);
        $target = Lead::create(['first_name' => 'Target', 'last_name' => 'Test', 'email' => 'target@example.test', 'proposed_program_ids' => [], 'proposal_review' => ['status' => 'verified']]);
        for ($i = 0; $i < 51; $i++) {
            Lead::create(['first_name' => 'Other', 'last_name' => 'Test', 'email' => 'other@example.test', 'proposed_program_ids' => [], 'proposal_review' => ['status' => 'pending']]);
        }
        $this->actingAs($user)->get('/program-verification?lead_id='.$target->id)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('selectedLeadId', $target->id)->where('selectedStatus', 'verified')->has('proposals', 1)->where('proposals.0.id', $target->id));
        $this->get('/program-verification?lead_id=999999')->assertNotFound();
    }

    public function test_exact_agreement_can_be_opened_even_when_outside_recent_queue(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['role' => 'education', 'module_permissions' => ['program_verification.consultancy']]);
        $target = Lead::create(['first_name' => 'Target', 'last_name' => 'Test', 'email' => 'target@example.test', 'consultancy_review' => ['status' => 'approved', 'approved_at' => now()->subMonth()->toIso8601String()]]);
        $this->actingAs($user)->get('/consultancy-verification?lead_id='.$target->id)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('selectedLeadId', $target->id)->has('proposals', 1)->where('proposals.0.id', $target->id));
        $this->get('/consultancy-verification?lead_id=999999')->assertNotFound();
    }

    public function test_exact_links_do_not_bypass_module_grants(): void
    {
        $user = User::factory()->create(['role' => 'education', 'module_permissions' => []]);
        foreach (['program-verification', 'consultancy-verification'] as $path) {
            $this->actingAs($user)->get('/'.$path.'?lead_id=1')->assertForbidden();
        }
    }
}
