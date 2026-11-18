<?php

namespace Tests\Feature\Portal;

use App\Models\Lead;
use App\Models\LeadContactProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sub-agent contact facts (best time to call, channel, languages, emergency
 * contact, goal) must persist to their own lead_contact_profiles table — never
 * onto the `leads` row — and merge rather than replace across separate saves.
 */
class SubAgentContactProfileTest extends TestCase
{
    use RefreshDatabase;

    private function makeSubAgentFor(Lead $lead): User
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead->forceFill(['agent_id' => $agent->id])->save();

        return User::factory()->create(['role' => 'sub_agent', 'parent_agent_id' => $agent->id]);
    }

    public function test_saving_contact_fields_writes_to_the_satellite_table_not_the_lead(): void
    {
        $lead = Lead::create(['first_name' => 'Ref', 'last_name' => 'Lead']);
        $subAgent = $this->makeSubAgentFor($lead);

        $this->actingAs($subAgent)
            ->post("/portal/sub-agent/leads/{$lead->id}/profile", [
                'best_time_to_call' => 'Weekday mornings',
                'preferred_channel' => 'WhatsApp',
                'languages' => 'English, Tagalog',
                'goal' => 'Study then work in NZ',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('lead_contact_profiles', [
            'lead_id' => $lead->id,
            'best_time_to_call' => 'Weekday mornings',
            'preferred_channel' => 'WhatsApp',
            'languages' => 'English, Tagalog',
            'goal' => 'Study then work in NZ',
        ]);

        // Exactly one profile row per lead.
        $this->assertSame(1, LeadContactProfile::where('lead_id', $lead->id)->count());
    }

    public function test_referral_case_names_require_admin_permission(): void
    {
        $lead = Lead::create(['first_name' => 'Original', 'last_name' => 'Applicant', 'is_immigration_case' => true]);
        $subAgent = $this->makeSubAgentFor($lead);
        $url = "/portal/sub-agent/leads/{$lead->id}/profile";

        $this->actingAs($subAgent)->postJson($url, ['first_name' => 'Changed'])
            ->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->post($url, ['phone' => '0210000000'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'first_name' => 'Original', 'phone' => '0210000000']);

        $unconverted = Lead::create(['first_name' => 'Unconverted', 'last_name' => 'Referral', 'agent_id' => $subAgent->parent_agent_id]);
        $this->post("/portal/sub-agent/leads/{$unconverted->id}/profile", ['first_name' => 'Corrected'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('leads', ['id' => $unconverted->id, 'first_name' => 'Corrected']);
    }

    public function test_a_later_save_merges_and_does_not_blank_earlier_fields(): void
    {
        $lead = Lead::create(['first_name' => 'Ref', 'last_name' => 'Lead']);
        $subAgent = $this->makeSubAgentFor($lead);

        // First save sets the goal.
        $this->actingAs($subAgent)
            ->post("/portal/sub-agent/leads/{$lead->id}/profile", ['goal' => 'Original goal'])
            ->assertRedirect();

        // Second save touches only the channel — the goal must survive.
        $this->actingAs($subAgent)
            ->post("/portal/sub-agent/leads/{$lead->id}/profile", ['preferred_channel' => 'Phone'])
            ->assertRedirect();

        $this->assertDatabaseHas('lead_contact_profiles', [
            'lead_id' => $lead->id,
            'goal' => 'Original goal',
            'preferred_channel' => 'Phone',
        ]);
    }

    public function test_the_leads_page_reflects_the_saved_contact_profile(): void
    {
        $lead = Lead::create(['first_name' => 'Ref', 'last_name' => 'Lead']);
        $subAgent = $this->makeSubAgentFor($lead);

        LeadContactProfile::create([
            'lead_id' => $lead->id,
            'preferred_channel' => 'Messenger',
            'goal' => 'Partner visa',
        ]);

        $this->actingAs($subAgent)->get('/portal/sub-agent/leads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portal/sub-agent/AgentLeads')
                ->where('leads.0.personal.preferred_channel', 'Messenger')
                ->where('leads.0.personal.goal', 'Partner visa'));
    }
}
