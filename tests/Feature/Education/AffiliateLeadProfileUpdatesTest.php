<?php

namespace Tests\Feature\Education;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AffiliateLeadProfileUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_education_affiliate_feed_and_profile_preserve_update_evidence(): void
    {
        $this->withoutVite();
        $staff = User::factory()->create(['role' => 'education']);
        $agent = User::factory()->create(['role' => 'agent']);
        $otherAgent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Referral', 'agent_id' => $agent->id, 'status' => 'New Leads']);
        Lead::create(['first_name' => 'Unrelated referral', 'last_name' => 'Synthetic', 'agent_id' => $otherAgent->id]);
        $lead->forceFill(['created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-11-20 01:00:00',
            'last_activity_at' => '2026-11-19 23:00:00', 'last_activity_by' => $staff->id,
            'last_activity_desc' => 'Internal note added'])->saveQuietly();
        $url = '/portal/education/agents/'.$agent->id.'/leads';
        $this->actingAs($staff)->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('portal/education/AgentLeads')->where('portalBase', '/portal/education')->has('leads', 1)
            ->where('leads.0.id', $lead->id)->where('leads.0.updated_by', $staff->name)
            ->where('leads.0.updated_desc', 'Internal note added')
            ->where('leads.0.updated_at', $lead->fresh()->last_activity_at->toJSON())
            ->where('leads.0.created_at', $lead->fresh()->created_at->toJSON()));
        $this->get('/portal/education/leads/'.$lead->id)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('portal/education/LeadDetails')->where('lead.id', $lead->id)->where('portalBase', '/portal/education'));
        $this->post('/portal/education/leads/'.$lead->id, ['status' => 'Consultancy Agreement'])->assertSessionHasNoErrors();
        $this->get($url)->assertInertia(fn (Assert $p) => $p->where('leads.0.status', 'Consultancy Agreement')
            ->where('leads.0.updated_by', $staff->name)->where('leads.0.updated_at', $lead->fresh()->last_activity_at->toJSON()));
        $this->assertSame($agent->id, $lead->fresh()->agent_id);
        $this->get('/portal/education/leads/999999999')->assertNotFound();
        $this->get('/portal/education/agents/'.$staff->id.'/leads')->assertNotFound();
    }

    public function test_legacy_update_fallback_and_existing_portal_access_checks(): void
    {
        $this->withoutVite();
        $staff = User::factory()->create(['role' => 'education']);
        $agent = User::factory()->create(['role' => 'agent']);
        $otherAgent = User::factory()->create(['role' => 'agent']);
        $lead = Lead::create(['first_name' => 'Legacy', 'last_name' => 'Synthetic', 'agent_id' => $agent->id]);
        $lead->forceFill(['last_activity_at' => null, 'last_activity_by' => null, 'updated_at' => '2026-10-01 02:00:00'])->saveQuietly();
        $this->actingAs($staff)->get('/portal/education/agents/'.$agent->id.'/leads')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('leads.0.updated_at', $lead->fresh()->updated_at->toJSON()));
        $this->actingAs($otherAgent)->get('/portal/education/agents/'.$agent->id.'/leads')->assertForbidden();
        $this->get('/portal/agent/leads/'.$lead->id)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'lead']))->get('/portal/education/leads/'.$lead->id)->assertForbidden();
    }
}

