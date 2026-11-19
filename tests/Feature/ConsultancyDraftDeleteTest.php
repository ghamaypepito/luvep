<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Services\ConsultancyReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConsultancyDraftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function draft(): Lead
    {
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Client']);
        ConsultancyReviewService::submit($lead, 'english_engagement', ['english_fee' => 14500, 'pte_fee' => 240], null);

        return $lead->fresh();
    }

    private function token(Lead $lead): string
    {
        return hash('sha256', json_encode($lead->consultancy_review, JSON_THROW_ON_ERROR));
    }

    public function test_delete_draft_preserves_issued_files_and_audits_review_without_sending(): void
    {
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $lead = $this->draft();
        $review = $lead->consultancy_review;
        $doc = LeadDocument::create(['lead_id' => $lead->id, 'checklist_key' => 'agree.consultancy', 'original_name' => 'Issued.pdf', 'file_path' => 'issued.pdf', 'source' => 'generated', 'status' => 'Staff shared']);
        Storage::disk('local')->put('issued.pdf', 'Existing issued agreement');
        $this->delete("/consultancy-verification/{$lead->id}/draft", ['delete_token' => $this->token($lead)])->assertRedirect('/consultancy-verification');
        $this->assertNull($lead->fresh()->consultancy_review);
        $this->assertDatabaseHas('lead_documents', ['id' => $doc->id]);
        Storage::disk('local')->assertExists('issued.pdf');
        $audit = ActivityLog::where('action', 'consultancy.draft.deleted')->sole();
        $this->assertSame($review, $audit->properties['review']);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        $this->get('/consultancy-verification')->assertInertia(fn ($page) => $page->has('proposals', 0));
    }

    public function test_verified_approved_and_replaced_drafts_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        foreach (['verified', 'approved'] as $status) {
            $lead = $this->draft();
            $review = $lead->consultancy_review;
            $review['status'] = $status;
            $lead->update(['consultancy_review' => $review]);
            $this->deleteJson("/consultancy-verification/{$lead->id}/draft", ['delete_token' => $this->token($lead)])->assertUnprocessable();
            $this->assertSame($review, $lead->fresh()->consultancy_review);
        }
        $lead = $this->draft();
        $token = $this->token($lead);
        ConsultancyReviewService::submit($lead, 'english_engagement', ['english_fee' => 18000], null);
        $this->deleteJson("/consultancy-verification/{$lead->id}/draft", ['delete_token' => $token])->assertUnprocessable();
        $this->assertNotNull($lead->fresh()->consultancy_review);
    }

    public function test_unauthorized_user_cannot_delete_draft(): void
    {
        $lead = $this->draft();
        $this->actingAs(User::factory()->create(['role' => 'education', 'module_permissions' => []]));
        $this->deleteJson("/consultancy-verification/{$lead->id}/draft", ['delete_token' => $this->token($lead)])->assertForbidden();
        $this->assertNotNull($lead->fresh()->consultancy_review);
    }

    public function test_missing_confirmation_token_and_issued_draft_flags_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));
        $lead = $this->draft();
        $this->deleteJson("/consultancy-verification/{$lead->id}/draft")->assertUnprocessable();
        $review = $lead->consultancy_review;
        $review['approved_at'] = now()->toIso8601String();
        $lead->update(['consultancy_review' => $review]);
        $this->deleteJson("/consultancy-verification/{$lead->id}/draft", ['delete_token' => $this->token($lead)])->assertUnprocessable();
        $this->get('/consultancy-verification')->assertInertia(fn ($page) => $page->where('proposals.0.delete_token', null));
    }
}
