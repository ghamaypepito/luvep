<?php

namespace Tests\Feature\Immigration;

use App\Models\CaseDependent;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DependentDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_dependent_displays_its_case_name_when_snapshot_is_blank(): void
    {
        $parent = Lead::create(['first_name' => 'Principal', 'last_name' => 'Example', 'is_immigration_case' => true]);
        $child = Lead::create(['first_name' => 'Taylor', 'last_name' => 'Example', 'is_immigration_case' => true]);
        CaseDependent::create(['lead_id' => $parent->id, 'linked_lead_id' => $child->id, 'relationship' => 'child', 'source' => 'staff']);
        $user = User::factory()->create(['role' => 'lead', 'lead_id' => $parent->id]);

        $this->actingAs($user)->get('/portal/lead/family')->assertOk()
            ->assertInertia(fn ($page) => $page->where('dependents.0.full_name', 'Taylor Example'));

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get("/portal/immigration/cases/{$parent->id}/profile")->assertOk()
            ->assertInertia(fn ($page) => $page->where('dependents.0.full_name', 'Taylor Example'));
    }

    public function test_unlinked_dependent_keeps_its_own_name_and_unnamed_placeholder(): void
    {
        $this->assertSame('Taylor Example', (new CaseDependent(['first_name' => 'Taylor', 'family_name' => 'Example']))->fullName());
        $this->assertSame('Unnamed dependant', (new CaseDependent)->fullName());
    }

    public function test_another_client_cannot_list_or_update_the_dependent(): void
    {
        $parent = Lead::create(['first_name' => 'Principal', 'last_name' => 'Example', 'is_immigration_case' => true]);
        $child = Lead::create(['first_name' => 'Private', 'last_name' => 'Child', 'is_immigration_case' => true]);
        $dep = CaseDependent::create(['lead_id' => $parent->id, 'linked_lead_id' => $child->id, 'relationship' => 'child', 'source' => 'staff']);
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Example']);
        $user = User::factory()->create(['role' => 'lead', 'lead_id' => $other->id]);

        $this->actingAs($user)->get('/portal/lead/family')->assertOk()
            ->assertInertia(fn ($page) => $page->has('dependents', 0));
        $this->put("/portal/lead/family/{$dep->id}", ['relationship' => 'child', 'first_name' => 'Changed'])->assertNotFound();
        $this->assertNull($dep->fresh()->first_name);
        $this->assertSame('Private', $child->fresh()->first_name);
    }
    public function test_agent_cannot_link_another_agents_case_by_posting_its_id(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'module_permissions' => ['referral_cases']]);
        $parent = Lead::create(['first_name' => 'Principal', 'last_name' => 'Example', 'agent_id' => $agent->id, 'is_immigration_case' => true]);
        $other = Lead::create(['first_name' => 'Private', 'last_name' => 'Child', 'is_immigration_case' => true]);

        $this->actingAs($agent)->post("/portal/agent/cases/{$parent->id}/dependents", [
            'relationship' => 'child', 'linked_lead_id' => $other->id,
        ])->assertSessionHasErrors('linked_lead_id');
        $this->assertDatabaseCount('case_dependents', 0);

        // A stale link created before validation was tightened must not reveal
        // the target identity to this agent either.
        $stale = CaseDependent::create(['lead_id' => $parent->id, 'linked_lead_id' => $other->id, 'relationship' => 'child', 'source' => 'staff']);
        $this->assertSame('Unnamed dependant', $stale->fullName());
        \App\Models\LeadDocument::create([
            'lead_id' => $other->id, 'original_name' => 'private-child-passport.pdf',
            'file_path' => 'private/child.pdf', 'mime' => 'application/pdf',
            'size' => 10, 'status' => 'Approved', 'source' => 'upload',
        ]);
        $this->get("/portal/agent/cases/{$parent->id}/profile")->assertOk()
            ->assertInertia(fn ($page) => $page->has('dependents', 0));
        $this->assertArrayNotHasKey('linked_case_name', $stale->checklistData());
        $this->assertCount(0, $stale->checklistData()['other_documents']);

        // The same explicit family link remains usable when both cases are in
        // the agent's assigned scope.
        $other->update(['agent_id' => $agent->id]);
        $this->get("/portal/agent/cases/{$parent->id}/profile")->assertOk()
            ->assertInertia(fn ($page) => $page->has('dependents', 1)
                ->where('dependents.0.full_name', 'Private Child')
                ->where('dependents.0.other_documents.0.original_name', 'private-child-passport.pdf'));



    }

    public function test_client_cannot_link_an_arbitrary_case_through_family_input(): void
    {
        $parent = Lead::create(['first_name' => 'Principal', 'last_name' => 'Example']);
        $other = Lead::create(['first_name' => 'Private', 'last_name' => 'Child', 'is_immigration_case' => true]);
        $user = User::factory()->create(['role' => 'lead', 'lead_id' => $parent->id]);
        $this->actingAs($user)->post('/portal/lead/family', [
            'relationship' => 'child', 'linked_lead_id' => $other->id,
        ])->assertSessionHasNoErrors();
        $this->assertNull(CaseDependent::sole()->linked_lead_id);
        $this->get('/portal/lead/family')->assertInertia(fn ($page) => $page->where('dependents.0.full_name', 'Unnamed dependant'));
    }

}
