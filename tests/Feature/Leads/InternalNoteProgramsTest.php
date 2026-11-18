<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadNote;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalNoteProgramsTest extends TestCase
{
    use RefreshDatabase;

    private function lead(array $values = []): Lead
    {
        return Lead::create(array_merge(['first_name' => 'Internal', 'last_name' => 'Notes'], $values));
    }

    public function test_multiple_programs_stay_on_internal_note_without_creating_a_proposal(): void
    {
        $staff = User::factory()->create(['role' => 'sales']);
        $lead = $this->lead();
        foreach (['Nursing', 'Business'] as $title) {
            Program::create(['title' => $title, 'slug' => strtolower($title), 'level' => 7, 'category' => 'bachelors', 'status' => 'published']);
        }
        $this->actingAs($staff)->post("/admin/leads/{$lead->id}/notes", [
            'body' => 'Discuss both at consultation.', 'programs' => ['Nursing', 'Business'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $note = LeadNote::where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame(['Nursing', 'Business'], $note->programs);
        $this->assertFalse($lead->proposals()->exists());
        $this->assertNull($lead->fresh()->proposed_program_ids);
        $this->getJson("/admin/leads/{$lead->id}/notes")->assertJsonPath('notes.0.programs', ['Nursing', 'Business']);
        $this->get("/portal/sales/leads/{$lead->id}")
            ->assertInertia(fn ($page) => $page->where('notes.0.programs', ['Nursing', 'Business']));
        $this->post("/admin/leads/{$lead->id}/notes/{$note->id}", ['body' => 'Updated internal note.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Nursing', 'Business'], $note->fresh()->programs);
    }

    public function test_unknown_program_is_rejected_and_another_agent_cannot_add_a_note(): void
    {
        $lead = $this->lead();
        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->post("/admin/leads/{$lead->id}/notes", ['body' => 'Invalid', 'programs' => ['Unknown course']])
            ->assertSessionHasErrors('programs.0');
        $owner = User::factory()->create(['role' => 'agent']);
        $lead->update(['agent_id' => $owner->id]);
        $this->actingAs(User::factory()->create(['role' => 'agent']))
            ->post("/portal/agent/leads/{$lead->id}/notes", ['body' => 'Not mine', 'programs' => []])
            ->assertNotFound();
        $this->assertFalse($lead->notes()->exists());
    }

    public function test_legacy_proposal_files_remain_accessible_without_a_requirement_row(): void
    {
        $lead = $this->lead();
        $doc = LeadDocument::create([
            'lead_id' => $lead->id, 'checklist_key' => 'agree.proposal',
            'original_name' => 'historical-proposal.pdf', 'file_path' => 'private/historical-proposal.pdf',
            'source' => 'upload', 'status' => 'Submitted',
        ]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get("/admin/leads/{$lead->id}")
            ->assertInertia(fn ($page) => $page->has('documentOrphans', 1)->where('documentOrphans.0.id', $doc->id));
        $this->assertSame('agree.proposal', $doc->fresh()->checklist_key);
    }
    public function test_portal_grants_do_not_allow_external_users_to_read_or_write_other_leads_notes(): void
    {
        $owner = User::factory()->create(['role' => 'agent']);
        $lead = $this->lead(['agent_id' => $owner->id]);
        foreach (['agent', 'lead'] as $role) {
            $user = User::factory()->create(['role' => $role, 'portal_access' => ['education']]);
            $this->actingAs($user)->getJson("/admin/leads/{$lead->id}/notes")->assertForbidden();
            $this->post("/admin/leads/{$lead->id}/notes", ['body' => 'Unauthorized'])->assertForbidden();
        }
        $this->assertFalse($lead->notes()->exists());
        $this->actingAs($owner)->post("/portal/agent/leads/{$lead->id}/notes", ['body' => 'Own referral discussion'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Own referral discussion', $lead->notes()->firstOrFail()->body);
    }

    public function test_reply_parent_must_belong_to_the_same_lead(): void
    {
        $staff = User::factory()->create(['role' => 'sales']);
        $lead = $this->lead();
        $other = $this->lead();
        $parent = LeadNote::create(['lead_id' => $other->id, 'user_id' => $staff->id, 'body' => 'Other lead']);
        $this->actingAs($staff)->post("/admin/leads/{$lead->id}/notes", ['body' => 'Cross-lead reply', 'parent_id' => $parent->id])
            ->assertSessionHasErrors('parent_id');
        $this->assertFalse($lead->notes()->exists());
    }

    public function test_retired_proposal_does_not_count_as_a_staff_requirement(): void
    {
        $builder = new class { use \App\Traits\BuildsLeadRow;
            public function totals(Lead $lead): array { return $this->leadChecklistTotals($lead); }
        };
        $education = $builder->totals($this->lead());
        $immigration = $builder->totals($this->lead(['is_immigration_case' => true]));
        $this->assertSame($immigration['total'] - 1, $education['total']);
    }

}
