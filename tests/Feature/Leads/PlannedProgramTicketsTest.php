<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlannedProgramTicketsTest extends TestCase
{
    use RefreshDatabase;

    private function programs(int $count): array
    {
        return collect(range(1, $count))->map(fn ($n) => Program::create([
            'title' => "Program {$n}", 'slug' => "program-{$n}", 'level' => 7,
            'category' => 'bachelors', 'status' => 'published',
        ])->id)->all();
    }

    public function test_proposal_saves_all_five_programs_and_rejects_a_sixth(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $lead = Lead::create(['first_name' => 'Proposal', 'last_name' => 'Test']);
        $ids = $this->programs(6);
        $five = array_slice($ids, 0, 5);
        $this->post("/admin/leads/{$lead->id}/proposal", ['program_ids' => $five])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($five, $lead->fresh()->proposed_program_ids);
        $this->assertSame($five, $lead->proposals()->first()->program_ids);
        $this->post("/admin/leads/{$lead->id}/proposal", ['program_ids' => $ids])
            ->assertSessionHasErrors('program_ids');
        $this->assertSame($five, $lead->fresh()->proposed_program_ids);
        $this->assertSame(1, $lead->proposals()->count());
    }

    public function test_shortlist_saves_five_and_rejects_six_without_creating_versions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $lead = Lead::create(['first_name' => 'Shortlist', 'last_name' => 'Test']);
        $ids = $this->programs(6);
        $five = array_slice($ids, 0, 5);
        $this->post("/admin/leads/{$lead->id}/shortlist", ['program_ids' => $five])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($five, $lead->fresh()->proposed_program_ids);
        $this->post("/admin/leads/{$lead->id}/shortlist", ['program_ids' => $ids])
            ->assertSessionHasErrors('program_ids');
        $this->assertSame($five, $lead->fresh()->proposed_program_ids);
        $this->assertFalse($lead->proposals()->exists());
    }

    public function test_agent_cannot_use_admin_proposal_or_shortlist_routes_for_another_lead(): void
    {
        $owner = User::factory()->create(['role' => 'agent']);
        $other = User::factory()->create(['role' => 'agent']);
        $lead = Lead::create(['first_name' => 'Private', 'last_name' => 'Test', 'agent_id' => $owner->id]);
        $ids = $this->programs(5);
        $this->actingAs($other);
        foreach (['proposal', 'shortlist'] as $action) {
            $this->post("/admin/leads/{$lead->id}/{$action}", ['program_ids' => $ids])->assertForbidden();
        }
        $this->assertNull($lead->fresh()->proposed_program_ids);
        $this->assertFalse($lead->proposals()->exists());
    }

    public function test_program_overview_and_post_study_persist_independently(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $id = $this->programs(1)[0];
        $this->post("/admin/programs/{$id}", [
            'title' => 'Program 1', 'level' => 7, 'category' => 'bachelors', 'status' => 'published',
            'description' => 'Overview content only', 'post_study' => 'Post study content only',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $program = Program::findOrFail($id);
        $this->assertSame('Overview content only', $program->description);
        $this->assertSame('Post study content only', $program->post_study);
    }

    public function test_registered_age_serializes_without_dob_and_dob_takes_precedence(): void
    {
        $lead = Lead::create(['first_name' => 'Age', 'last_name' => 'Test']);
        $lead->forceFill(['age' => 37])->save();
        $this->assertSame(37, $lead->fresh()->toArray()['age']);
        $lead->forceFill(['dob' => now()->subYears(25)->subDays(1)->toDateString()])->save();
        $this->assertSame(25, $lead->fresh()->toArray()['age']);
    }
}
