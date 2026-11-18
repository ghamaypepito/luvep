<?php

namespace Tests\Feature\Immigration;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Models\VisaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Build 11.D Phase 4 — Case Profile documents tab integration.
 *
 * Verifies the controller props feed the documents tab correctly:
 * checklist + grouped + progress + unstructured. Also pins the
 * approve/reject path (which reuses LeadDocumentController::updateStatus,
 * not a parallel endpoint).
 */
class CaseDocumentsTabTest extends TestCase
{
    use RefreshDatabase;

    private function makeCase(array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'first_name'          => 'Visa',
            'last_name'           => 'Applicant',
            'email'               => 'visa@example.test',
            'is_immigration_case' => true,
            'inz_visa_type'       => 'Student Visa',
        ], $attrs));
    }

    private function makeVisaTypeWithChecklist(string $name = 'Student Visa', ?array $items = null): VisaType
    {
        return VisaType::create([
            'code'                   => strtolower(str_replace(' ', '_', $name)),
            'name'                   => $name,
            'category'               => 'Student',
            'consultation_price_nzd' => 250,
            'active'                 => true,
            'checklist_items'        => $items ?? [
                ['key' => 'passport', 'label' => 'Passport',          'required' => true],
                ['key' => 'ielts',    'label' => 'IELTS Certificate', 'required' => true],
                ['key' => 'cv',       'label' => 'Curriculum Vitae',  'required' => false],
            ],
        ]);
    }

    public function test_documents_tab_renders_checklist_for_known_visa_type(): void
    {
        $this->makeVisaTypeWithChecklist();
        $case = $this->makeCase();

        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->get("/portal/immigration/cases/{$case->id}/profile")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('checklist.source', 'visa_type')
                ->where('checklist.visa', 'Student Visa')
                ->has('checklist.items', 4)
                ->where('checklist.items.0.key', 'svf')
                ->where('checklist.items.0.label', 'Student Visa Information Form')
                ->where('checklist.items.0.required', true)
                ->where('checklist.items.0.status', 'not_submitted')
                ->has('checklistGrouped.Information Form', 1)
                ->where('checklistGrouped.Information Form.0.key', 'svf')
                ->has('checklistGrouped.Required Documents', 3)
            );
    }

    public function test_documents_tab_keeps_universal_form_when_visa_type_unknown(): void
    {
        // Missing catalogue data still requires the universal form and keeps
        // unrelated uploads available to staff.
        $case = $this->makeCase(['inz_visa_type' => 'Made-up Visa']);
        LeadDocument::create([
            'lead_id'       => $case->id,
            'checklist_key' => null,
            'original_name' => 'orphan.pdf',
            'file_path'     => 'lead-documents/x.pdf',
            'mime'          => 'application/pdf',
            'size'          => 100,
            'status'        => LeadDocument::STATUS_SUBMITTED,
            'source'        => LeadDocument::SOURCE_UPLOAD,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->get("/portal/immigration/cases/{$case->id}/profile")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('checklist.source', 'none')
                ->has('checklist.items', 1)
                ->where('checklist.items.0.key', 'svf')
                ->where('checklist.items.0.label', 'Visa Information Form')
                ->where('checklist.items.0.required', true)
                ->has('checklistGrouped', 1)
                ->has('checklistGrouped.Information Form', 1)
                ->where('checklistProgress.required_total', 1)
                ->where('checklistProgress.required_approved', 0)
                ->has('unstructuredDocuments', 1)
                ->where('unstructuredDocuments.0.original_name', 'orphan.pdf')
            );
    }

    public function test_documents_tab_progress_counts_required_items_only(): void
    {
        $this->makeVisaTypeWithChecklist('Student Visa', [
            ['key' => 'a', 'label' => 'A', 'required' => true],
            ['key' => 'b', 'label' => 'B', 'required' => true],
            ['key' => 'c', 'label' => 'C', 'required' => false],
        ]);
        $case = $this->makeCase();
        // Approve the optional one — should NOT count in required_approved.
        LeadDocument::create([
            'lead_id'       => $case->id, 'checklist_key' => 'c',
            'original_name' => 'c.pdf', 'file_path' => 'p/c.pdf',
            'mime' => 'application/pdf', 'size' => 1,
            'status' => LeadDocument::STATUS_APPROVED, 'source' => LeadDocument::SOURCE_UPLOAD,
        ]);
        // Approve one required.
        LeadDocument::create([
            'lead_id'       => $case->id, 'checklist_key' => 'a',
            'original_name' => 'a.pdf', 'file_path' => 'p/a.pdf',
            'mime' => 'application/pdf', 'size' => 1,
            'status' => LeadDocument::STATUS_APPROVED, 'source' => LeadDocument::SOURCE_UPLOAD,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->get("/portal/immigration/cases/{$case->id}/profile")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('checklistProgress.required_total', 3)
                ->where('checklistProgress.required_approved', 1)
                ->where('checklistProgress.total', 4)
                ->where('checklistProgress.approved', 2)
            );
    }

    public function test_consultant_can_approve_document_via_existing_endpoint(): void
    {
        $case = $this->makeCase();
        $doc  = LeadDocument::create([
            'lead_id'       => $case->id, 'checklist_key' => 'passport',
            'original_name' => 'p.pdf', 'file_path' => 'p/p.pdf',
            'mime' => 'application/pdf', 'size' => 1,
            'status' => LeadDocument::STATUS_SUBMITTED, 'source' => LeadDocument::SOURCE_UPLOAD,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->from("/portal/immigration/cases/{$case->id}/profile")
            ->post("/admin/leads/{$case->id}/documents/{$doc->id}/status", [
                'status' => LeadDocument::STATUS_APPROVED,
            ])
            ->assertRedirect();

        $this->assertSame(LeadDocument::STATUS_APPROVED, $doc->fresh()->status);
    }

    public function test_consultant_can_reject_with_note_via_existing_endpoint(): void
    {
        $case = $this->makeCase();
        $doc  = LeadDocument::create([
            'lead_id'       => $case->id, 'checklist_key' => 'passport',
            'original_name' => 'p.pdf', 'file_path' => 'p/p.pdf',
            'mime' => 'application/pdf', 'size' => 1,
            'status' => LeadDocument::STATUS_SUBMITTED, 'source' => LeadDocument::SOURCE_UPLOAD,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->from("/portal/immigration/cases/{$case->id}/profile")
            ->post("/admin/leads/{$case->id}/documents/{$doc->id}/status", [
                'status' => LeadDocument::STATUS_REJECTED,
                'note'   => 'Bio page missing — please re-upload with bio side included.',
            ])
            ->assertRedirect();

        $fresh = $doc->fresh();
        $this->assertSame(LeadDocument::STATUS_REJECTED, $fresh->status);
        $this->assertStringContainsString('Bio page missing', $fresh->note);
    }

    public function test_consultant_can_request_document_from_client(): void
    {
        $case = $this->makeCase();

        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->from("/portal/immigration/cases/{$case->id}/profile")
            ->post("/admin/leads/{$case->id}/documents/requests", [
                'items' => [
                    ['label' => 'Passport biographical page', 'description' => 'Photo side only', 'required' => true],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('lead_document_requests', [
            'lead_id' => $case->id,
            'label'   => 'Passport biographical page',
        ]);
    }

    public function test_documents_tab_role_gate_matches_case_profile(): void
    {
        // Sales staff hitting the case-profile route should still 403 —
        // Phase 4's checklist data is gated by the same role check as
        // Phase 1's CaseProfileController.
        $this->makeVisaTypeWithChecklist();
        $case = $this->makeCase();

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->get("/portal/immigration/cases/{$case->id}/profile")
            ->assertForbidden();
    }
}
