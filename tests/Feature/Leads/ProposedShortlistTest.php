<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Lead Stats "Programs offered" card edits leads.proposed_program_ids
 * inline WITHOUT spawning a proposal version, and drops the client's chosen
 * program if it falls off the shortlist.
 */
class ProposedShortlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_inline_add_sets_shortlist_without_a_version(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $lead = Lead::create(['first_name' => 'A', 'last_name' => 'L', 'status' => 'New Leads']);
        $a = Program::create(['title' => 'Program A', 'slug' => 'program-a', 'level' => '7', 'location' => 'Auckland', 'category' => 'bachelors', 'status' => 'published']);
        $b = Program::create(['title' => 'Program B', 'slug' => 'program-b', 'level' => '7', 'location' => 'Auckland', 'category' => 'bachelors', 'status' => 'published']);

        $this->post("/admin/leads/{$lead->id}/shortlist", ['program_ids' => [$a->id, $b->id]])
            ->assertRedirect();

        $this->assertSame([$a->id, $b->id], $lead->fresh()->proposed_program_ids);
        $this->assertFalse($lead->proposals()->exists(), 'inline edit must not create a proposal version');
    }

    public function test_removing_the_chosen_program_clears_the_selection(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $a = Program::create(['title' => 'Program A', 'slug' => 'program-a', 'level' => '7', 'location' => 'Auckland', 'category' => 'bachelors', 'status' => 'published']);
        $b = Program::create(['title' => 'Program B', 'slug' => 'program-b', 'level' => '7', 'location' => 'Auckland', 'category' => 'bachelors', 'status' => 'published']);
        $lead = Lead::create([
            'first_name' => 'A', 'last_name' => 'L', 'status' => 'New Leads',
            'proposed_program_ids' => [$a->id, $b->id], 'preferred_program_id' => $a->id,
        ]);

        // Drop program A (the chosen one).
        $this->post("/admin/leads/{$lead->id}/shortlist", ['program_ids' => [$b->id]])
            ->assertRedirect();

        $fresh = $lead->fresh();
        $this->assertSame([$b->id], $fresh->proposed_program_ids);
        $this->assertNull($fresh->preferred_program_id);
    }
}
