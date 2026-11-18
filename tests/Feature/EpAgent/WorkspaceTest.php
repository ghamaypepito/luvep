<?php

namespace Tests\Feature\EpAgent;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function enable(User $user): void
    {
        config(['ep_agent.enabled' => true, 'ep_agent.ui_enabled' => true, 'ep_agent.pilot_user_ids' => [$user->id]]);
    }

    public function test_ui_requires_both_switches_explicit_pilot_and_current_identity(): void
    {
        $user = User::factory()->create(['role' => 'education']);
        $this->actingAs($user)->get('/ep-agent')->assertForbidden();
        config(['ep_agent.enabled' => true, 'ep_agent.ui_enabled' => true]);
        $this->get('/ep-agent')->assertForbidden();
        $this->enable($user);
        $this->get('/ep-agent')->assertOk()->assertInertia(fn ($p) => $p->component('ai/EpWorkspace')->where('ep.portals', ['education'])->where('ep.paid_enabled', false)->where('ep.context', null));
        $user->update(['is_active' => false]);
        $this->getJson('/ep-agent')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_admin_pilot_restricts_foundation_apis_as_well_as_navigation(): void
    {
        config(['ep_agent.enabled' => true, 'ep_agent.ui_enabled' => true, 'ep_agent.pilot_admins' => true]);
        $staff = User::factory()->create(['role' => 'education']);
        $this->actingAs($staff)->postJson('/api/ep-agent/conversations', ['portal' => 'education'])->assertForbidden();
        $this->getJson('/api/ep-agent/conversations')->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/ep-agent/conversations', ['portal' => 'education'])->assertCreated();
    }

    public function test_page_and_context_are_scoped_and_conversation_ownership_wins_over_query(): void
    {
        $user = User::factory()->create(['role' => 'education']);
        $this->enable($user);
        $lead = Lead::create(['first_name' => 'Private', 'last_name' => 'Student', 'is_student' => true, 'assigned_to' => $user->id]);
        $this->actingAs($user)->get('/ep-agent?lead_id='.$lead->id.'&portal=education')->assertOk()->assertInertia(fn ($p) => $p->where('ep.context.id', $lead->id)->where('ep.context.label', 'Private Student'));
        $this->getJson('/api/ep-agent/context?lead_id='.$lead->id.'&portal=education')->assertOk()->assertJsonPath('context.id', $lead->id);
        $lead->update(['assigned_to' => null]);
        $this->getJson('/api/ep-agent/context?lead_id='.$lead->id.'&portal=education')->assertForbidden();
        $id = \App\EpAgent\Conversation::create(['user_id' => $user->id, 'portal' => 'education', 'title' => 'Mine'])->id;
        $other = User::factory()->create(['role' => 'admin']);
        $this->enable($other);
        $this->actingAs($other)->getJson('/ep-agent?conversation_id='.$id)->assertNotFound();
    }

    public function test_admin_pilot_and_manager_visibility_do_not_enable_external_or_impersonated_users(): void
    {
        config(['ep_agent.enabled' => true, 'ep_agent.ui_enabled' => true, 'ep_agent.pilot_admins' => true]);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->get('/ep-agent')->assertOk()->assertInertia(fn ($p) => $p->where('auth.ep_agent.enabled', true)->where('auth.ep_agent.manager', true));
        $this->get('/ep-manager')->assertOk();
        $external = User::factory()->create(['role' => 'agent', 'portal_access' => ['education']]);
        $this->enable($external);
        $this->actingAs($external)->getJson('/ep-agent')->assertForbidden();
        $this->actingAs($admin)->postJson('/admin/user-view/'.$external->id)->assertOk();
        $this->getJson('/ep-agent')->assertForbidden();
        $this->getJson('/ep-manager')->assertForbidden();
    }
}
