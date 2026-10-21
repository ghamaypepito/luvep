<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadProposal;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proposal version history: creating a new proposal must NOT discard the
 * previous one. Each non-empty save snapshots to lead_proposals while the
 * lead's active proposed_program_ids tracks the latest (what the tracker shows).
 */
class ProposalHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function program(string $title): Program
    {
        return Program::create([
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title),
            'level' => '7',
            'location' => 'Auckland',
            'category' => 'bachelors',
            'status' => 'published',
        ]);
    }

    public function test_new_proposal_keeps_previous_versions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $lead = Lead::create(['first_name' => 'Prop', 'last_name' => 'Lead', 'status' => 'Consultation Done']);
        $a = $this->program('Bachelor of Accounting');
        $b = $this->program('Bachelor of Nursing');
        $c = $this->program('Bachelor of IT');

        // First proposal.
        $this->post("/admin/leads/{$lead->id}/proposal", ['program_ids' => [$a->id, $b->id]])
            ->assertRedirect();

        // Second proposal — a completely different set.
        $this->post("/admin/leads/{$lead->id}/proposal", ['program_ids' => [$c->id]])
            ->assertRedirect();

        // Both versions are kept in history …
        $this->assertSame(2, LeadProposal::where('lead_id', $lead->id)->count());

        // … the active shortlist is the latest one …
        $this->assertSame([$c->id], $lead->fresh()->proposed_program_ids);

        // … and the newest history row matches the active set.
        $latest = LeadProposal::where('lead_id', $lead->id)->latest()->first();
        $this->assertSame([$c->id], $latest->program_ids);
    }

    public function test_legacy_active_proposal_is_preserved_on_first_new_save(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $a = $this->program('Bachelor of Accounting');
        $b = $this->program('Bachelor of Nursing');

        // Legacy lead: an active proposal already exists with NO history rows
        // (it predates versioning).
        $lead = Lead::create([
            'first_name' => 'Legacy', 'last_name' => 'Lead', 'status' => 'Consultation Done',
            'proposed_program_ids' => [$a->id],
        ]);
        $this->assertSame(0, LeadProposal::where('lead_id', $lead->id)->count());

        // First versioned save replaces it — the old set must be kept.
        $this->post("/admin/leads/{$lead->id}/proposal", ['program_ids' => [$b->id]])
            ->assertRedirect();

        $history = LeadProposal::where('lead_id', $lead->id)->orderBy('id')->pluck('program_ids')->all();
        $this->assertCount(2, $history);
        $this->assertSame([$a->id], $history[0]); // backfilled legacy proposal
        $this->assertSame([$b->id], $history[1]); // the new one
        $this->assertSame([$b->id], $lead->fresh()->proposed_program_ids);
    }

    public function test_new_proposal_clears_selection_and_freezes_it_on_the_old_version(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $a = $this->program('Bachelor of Accounting');
        $b = $this->program('Bachelor of Nursing');
        $c = $this->program('Bachelor of IT');

        $lead = Lead::create(['first_name' => 'Prop', 'last_name' => 'Lead', 'status' => 'Consultation Done']);

        // First proposal, and the client picks program A.
        $this->post("/admin/leads/{$lead->id}/proposal", ['program_ids' => [$a->id, $b->id]])->assertRedirect();
        $lead->forceFill(['preferred_program_id' => $a->id, 'preferred_program_chosen_at' => now()])->save();

        // Staff create a NEW proposal.
        $this->post("/admin/leads/{$lead->id}/proposal", ['program_ids' => [$c->id]])->assertRedirect();

        $lead->refresh();
        // The new active proposal has NOTHING selected by default …
        $this->assertNull($lead->preferred_program_id);

        // … and the superseded version keeps the program that had been chosen.
        $superseded = LeadProposal::where('lead_id', $lead->id)
            ->where('program_ids', json_encode([$a->id, $b->id]))
            ->first();
        $this->assertNotNull($superseded);
        $this->assertSame($a->id, $superseded->selected_program_id);
    }

    public function test_proposal_email_shows_only_selected_program_options(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        // A study-proposal template using the flexible {{program_options}} block.
        \App\Models\MessageTemplate::create([
            'key' => 'program_proposal', 'name' => 'Study Proposal', 'channels' => ['email'],
            'email_subject' => 'Proposal', 'email_body' => 'Options: {{program_options}}',
        ]);

        $a = $this->program('Bachelor of Accounting');
        $b = $this->program('Bachelor of Nursing');
        $lead = Lead::create([
            'first_name' => 'Opt', 'last_name' => 'Lead', 'email' => 'opt@example.com',
            'status' => 'Consultation Done', 'tracking_code' => 'OPTX-OPTX-OPTX',
            'proposed_program_ids' => [$a->id, $b->id],
        ]);

        $this->post("/admin/leads/{$lead->id}/notify-document-ready", ['kind' => 'proposal'])
            ->assertRedirect();

        $log = \App\Models\MessageLog::where('template_key', 'program_proposal')->latest()->first();
        $this->assertNotNull($log);
        // Exactly two options — the two selected — and no empty Option 3.
        $this->assertStringContainsString('Option 1:', $log->body);
        $this->assertStringContainsString('Bachelor of Accounting', $log->body);
        $this->assertStringContainsString('Option 2:', $log->body);
        $this->assertStringContainsString('Bachelor of Nursing', $log->body);
        $this->assertStringNotContainsString('Option 3:', $log->body);
    }

    public function test_clearing_does_not_add_a_history_row(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $lead = Lead::create(['first_name' => 'Prop', 'last_name' => 'Lead', 'status' => 'Consultation Done']);
        $a = $this->program('Bachelor of Accounting');

        $this->post("/admin/leads/{$lead->id}/proposal", ['program_ids' => [$a->id]])->assertRedirect();
        $this->post("/admin/leads/{$lead->id}/proposal", ['program_ids' => []])->assertRedirect();

        // The clear resets the active shortlist but keeps the one real version.
        $this->assertNull($lead->fresh()->proposed_program_ids);
        $this->assertSame(1, LeadProposal::where('lead_id', $lead->id)->count());
    }
}
