<?php

namespace Tests\Feature\Leads;

use App\Models\CaseThread;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlannedDocumentTicketsTest extends TestCase
{
    use RefreshDatabase;

    private function lead(array $attributes = []): Lead
    {
        return Lead::create(array_merge(['first_name' => 'Document', 'last_name' => 'Test'], $attributes));
    }

    private function document(Lead $lead): LeadDocument
    {
        Storage::disk('local')->put("lead-documents/{$lead->id}/cv.pdf", 'test pdf');
        return LeadDocument::create([
            'lead_id' => $lead->id, 'checklist_key' => 'acad.cv', 'original_name' => 'cv.pdf',
            'file_path' => "lead-documents/{$lead->id}/cv.pdf", 'status' => 'Submitted', 'source' => 'upload',
        ]);
    }

    public function test_sales_can_add_document_and_education_can_comment_on_it(): void
    {
        $lead = $this->lead();
        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->post("/admin/leads/{$lead->id}/documents/custom", ['name' => 'Additional assessment evidence'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $key = $lead->fresh()->custom_documents[0]['key'];
        $education = User::factory()->create(['role' => 'education']);
        $this->actingAs($education)->post("/admin/leads/{$lead->id}/threads", [
            'anchor_type' => 'document', 'anchor_key' => $key, 'body' => 'Please provide a clearer scan.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('case_threads', [
            'lead_id' => $lead->id, 'anchor_key' => $key, 'author_id' => $education->id,
            'body' => 'Please provide a clearer scan.',
        ]);
    }

    public function test_admin_can_reject_initial_assessment_file_with_reason(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = $this->lead();
        $doc = $this->document($lead);
        $this->actingAs($admin)->post("/admin/leads/{$lead->id}/documents/{$doc->id}/status", [
            'status' => 'Rejected', 'note' => 'Wrong CV; please replace.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $doc->refresh();
        $this->assertSame('Rejected', $doc->status);
        $this->assertSame('Wrong CV; please replace.', $doc->note);
        $this->assertSame($admin->id, $doc->reviewed_by);
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_wrong_uploaded_file_can_be_removed_without_deleting_another_leads_file(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        $lead = $this->lead();
        $other = $this->lead();
        $doc = $this->document($lead);
        $otherDoc = $this->document($other);
        $this->delete("/admin/leads/{$lead->id}/documents/{$otherDoc->id}")->assertNotFound();
        $this->delete("/admin/leads/{$lead->id}/documents/{$doc->id}")
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('lead_documents', ['id' => $doc->id]);
        Storage::disk('local')->assertMissing($doc->file_path);
        Storage::disk('local')->assertExists($otherDoc->file_path);
    }

    public function test_non_owner_agent_cannot_delete_reject_or_comment_on_another_leads_file(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'agent']);
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $lead = $this->lead(['agent_id' => $owner->id]);
        $doc = $this->document($lead);
        $this->delete("/portal/agent/leads/{$lead->id}/documents/{$doc->id}")->assertNotFound();
        $this->post("/admin/leads/{$lead->id}/documents/{$doc->id}/status", ['status' => 'Rejected'])->assertForbidden();
        $this->post("/portal/agent/leads/{$lead->id}/threads", [
            'anchor_type' => 'document', 'anchor_key' => 'acad.cv', 'body' => 'Unauthorized',
        ])->assertNotFound();
        $this->assertSame('Submitted', $doc->fresh()->status);
        Storage::disk('local')->assertExists($doc->file_path);
        $this->assertFalse(CaseThread::where('lead_id', $lead->id)->exists());
    }
}
