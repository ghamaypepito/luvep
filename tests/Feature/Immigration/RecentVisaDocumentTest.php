<?php

namespace Tests\Feature\Immigration;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Models\VisaType;
use App\Services\Immigration\CaseChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentVisaDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function caseRecord(): Lead
    {
        return Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Applicant',
            'is_immigration_case' => true, 'inz_visa_type' => 'Synthetic Visa',
            'document_checklist' => [['key' => 'passport', 'label' => 'Passport', 'required' => true]]]);
    }

    private function document(Lead $lead, array $extra = []): LeadDocument
    {
        return LeadDocument::create(array_merge(['lead_id' => $lead->id, 'original_name' => 'synthetic-visa.pdf',
            'file_path' => 'private/synthetic-visa.pdf', 'mime' => 'application/pdf', 'size' => 120,
            'source' => 'upload', 'status' => 'StaffShared', 'note' => 'Keep this review note'], $extra));
    }

    private function url(Lead $lead, LeadDocument $doc): string
    {
        return "/portal/immigration/cases/{$lead->id}/documents/{$doc->id}/recent-visa";
    }

    public function test_manager_can_classify_without_reupload_or_review_reset_and_retry_is_idempotent(): void
    {
        $user = User::factory()->create(['role' => 'immigration_manager']);
        $lead = $this->caseRecord();
        $other = $this->caseRecord();
        $doc = $this->document($lead, ['checklist_key' => 'legacy.visa', 'uploaded_by' => $user->id,
            'reviewed_by' => $user->id, 'reviewed_at' => now()->subDay()]);
        $before = $doc->getAttributes();
        $progress = app(CaseChecklistService::class)->progress($lead);

        $this->actingAs($user)->post($this->url($lead, $doc))->assertRedirect()->assertSessionHasNoErrors();
        $lead->refresh();
        $doc->refresh();
        $this->assertSame('recent_visa', $doc->checklist_key);
        foreach (['file_path', 'original_name', 'status', 'note', 'uploaded_by', 'reviewed_by', 'reviewed_at', 'created_at'] as $field) {
            $this->assertEquals($before[$field], $doc->getAttributes()[$field], $field);
        }
        $resolver = app(CaseChecklistService::class);
        $item = collect($resolver->forCase($lead))->firstWhere('key', 'recent_visa');
        $this->assertSame('Visa applications & decisions', $item['category']);
        $this->assertFalse($item['required']);
        $this->assertContains('recent_visa', $lead->hidden_track_documents);
        $this->assertSame($progress['required_total'], $resolver->progress($lead)['required_total']);
        $this->assertSame([], $resolver->unstructuredDocuments($lead));
        $this->assertNotContains('recent_visa', array_column($resolver->forCase($other->refresh()), 'key'));

        $this->post($this->url($lead, $doc))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, collect($resolver->forCase($lead->refresh()))->where('key', 'recent_visa')->count());
        $this->assertSame(1, ActivityLog::where('action', 'case.document.categorized')->count());
        $this->assertSame(1, LeadDocument::where('lead_id', $lead->id)->count());
        $second = $this->document($lead, ['source_variant' => 'outcome:approved_visa']);
        $this->post($this->url($lead, $second))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('outcome:approved_visa', $second->fresh()->source_variant);
        $this->assertSame(2, LeadDocument::where('lead_id', $lead->id)->where('checklist_key', 'recent_visa')->count());
    }

    public function test_catalogue_is_not_changed_and_existing_case_checklist_items_are_preserved(): void
    {
        $visa = VisaType::create(['name' => 'Synthetic Visa', 'code' => 'SYN', 'category' => 'Work',
            'active' => true, 'checklist_items' => [['key' => 'passport', 'label' => 'Passport', 'required' => true]]]);
        $lead = $this->caseRecord();
        $lead->update(['document_checklist' => null]);
        $doc = $this->document($lead);
        $this->actingAs(User::factory()->create(['role' => 'immigration']))->post($this->url($lead, $doc))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['passport'], array_column($visa->fresh()->checklist_items, 'key'));
        $this->assertContains('passport', array_column(app(CaseChecklistService::class)->forCase($lead->fresh()), 'key'));
    }

    public function test_cross_case_ids_non_cases_and_external_users_are_denied(): void
    {
        $lead = $this->caseRecord();
        $other = $this->caseRecord();
        $doc = $this->document($other);
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']))
            ->post($this->url($lead, $doc))->assertNotFound();
        $other->update(['is_immigration_case' => false]);
        $this->post($this->url($other, $doc))->assertNotFound();
        foreach (['sales', 'education', 'lead', 'agent', 'finance'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['immigration']]))
                ->post($this->url($lead, $doc))->assertForbidden();
        }
    }

    public function test_matched_documents_generated_files_and_special_workflows_are_not_reclassified(): void
    {
        $lead = $this->caseRecord();
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
        foreach ([['checklist_key' => 'passport'], ['source' => 'generated'], ['source_variant' => 'proof_of_payment'],
            ['source_variant' => 'rfi'], ['checklist_key' => 'rfi']] as $extra) {
            $doc = $this->document($lead, $extra);
            $this->post($this->url($lead, $doc))->assertUnprocessable();
            $this->assertSame($extra['checklist_key'] ?? null, $doc->fresh()->checklist_key);
        }
        $this->assertNotContains('recent_visa', array_column(app(CaseChecklistService::class)->forCase($lead->fresh()), 'key'));
    }
}
