<?php

namespace Tests\Unit\Services;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\VisaType;
use App\Services\Immigration\CaseChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Build 11.D Phase 4 — CaseChecklistService unit coverage.
 *
 * Pins the resolution-order semantics (per-lead override → visa-type →
 * universal-only fallback), the required universal information form,
 * status attachment, category grouping, and defensive normalisation.
 */
class CaseChecklistServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CaseChecklistService
    {
        return app(CaseChecklistService::class);
    }

    private function makeCase(array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'first_name'          => 'Pat',
            'last_name'           => 'Patel',
            'email'               => 'pat@example.test',
            'is_immigration_case' => true,
            'inz_visa_type'       => 'Student Visa',
        ], $attrs));
    }

    private function makeVisaType(string $name, array $items): VisaType
    {
        return VisaType::create([
            'code'                   => strtolower(str_replace(' ', '_', $name)),
            'name'                   => $name,
            'category'               => 'Student',
            'consultation_price_nzd' => 250,
            'active'                 => true,
            'checklist_items'        => $items,
        ]);
    }

    public function test_01_for_case_returns_visa_type_checklist_when_visa_type_matches(): void
    {
        $this->makeVisaType('Student Visa', [
            ['key' => 'passport', 'label' => 'Passport',         'required' => true],
            ['key' => 'ielts',    'label' => 'IELTS Certificate', 'required' => true],
        ]);
        $lead = $this->makeCase();

        $items = $this->service()->forCase($lead);

        $this->assertSame(['svf', 'passport', 'ielts'], array_column($items, 'key'));
        $this->assertSame('Student Visa Information Form', $items[0]['label']);
        $this->assertTrue($items[0]['required']);
    }

    public function test_02_for_case_returns_universal_form_when_no_visa_type(): void
    {
        $this->makeVisaType('Student Visa', [['key' => 'passport', 'label' => 'Passport']]);
        $lead = $this->makeCase(['inz_visa_type' => null]);

        $this->assertSame([
            ['key' => 'svf', 'label' => 'Visa Information Form', 'hint' => 'Visa information form prepared with your adviser.', 'required' => true, 'category' => 'Information Form'],
        ], $this->service()->forCase($lead));
    }

    public function test_03_for_case_returns_universal_form_when_visa_type_does_not_match(): void
    {
        $this->makeVisaType('Student Visa', [['key' => 'passport', 'label' => 'Passport']]);
        $lead = $this->makeCase(['inz_visa_type' => 'Some Made-up Visa']);

        $this->assertSame([
            ['key' => 'svf', 'label' => 'Visa Information Form', 'hint' => 'Visa information form prepared with your adviser.', 'required' => true, 'category' => 'Information Form'],
        ], $this->service()->forCase($lead));
    }

    public function test_04_for_case_uses_lead_override_when_set(): void
    {
        $this->makeVisaType('Student Visa', [['key' => 'passport', 'label' => 'Passport']]);
        // Per-lead override wins over visa-type catalog.
        $lead = $this->makeCase([
            'document_checklist' => [
                ['key' => 'custom_a', 'label' => 'Custom Doc A', 'required' => true],
                ['key' => 'custom_b', 'label' => 'Custom Doc B', 'required' => false],
            ],
        ]);

        $items = $this->service()->forCase($lead);
        $this->assertSame(['svf', 'custom_a', 'custom_b'], array_column($items, 'key'));
        $this->assertTrue($items[0]['required']);
    }

    public function test_05_for_case_drops_malformed_items_defensively(): void
    {
        $this->makeVisaType('Student Visa', [
            ['key' => 'passport', 'label' => 'Passport'],
            ['key' => '',         'label' => 'Missing key'],   // dropped
            ['label' => 'Missing key entirely'],                // dropped
            ['key' => 'no_label_either'],                       // dropped (no label)
            'not even an array',                                // dropped
        ]);
        $lead = $this->makeCase();

        $items = $this->service()->forCase($lead);

        $this->assertSame(['svf', 'passport'], array_column($items, 'key'));
    }

    public function test_06_with_statuses_marks_not_submitted_when_no_document(): void
    {
        $this->makeVisaType('Student Visa', [
            ['key' => 'passport', 'label' => 'Passport', 'required' => true],
        ]);
        $lead = $this->makeCase();

        $items = collect($this->service()->withStatuses($lead))->keyBy('key');

        foreach (['svf', 'passport'] as $key) {
            $this->assertSame('not_submitted', $items[$key]['status']);
            $this->assertNull($items[$key]['document_id']);
        }
    }

    public function test_07_with_statuses_attaches_approved_status_with_upload_timestamp(): void
    {
        $this->makeVisaType('Student Visa', [
            ['key' => 'passport', 'label' => 'Passport', 'required' => true],
        ]);
        $lead = $this->makeCase();
        $doc = LeadDocument::create([
            'lead_id'       => $lead->id,
            'checklist_key' => 'passport',
            'original_name' => 'passport.pdf',
            'file_path'     => 'lead-documents/x.pdf',
            'mime'          => 'application/pdf',
            'size'          => 1000,
            'status'        => LeadDocument::STATUS_APPROVED,
            'source'        => LeadDocument::SOURCE_UPLOAD,
        ]);

        $items = collect($this->service()->withStatuses($lead))->keyBy('key');

        $this->assertSame('Approved', $items['passport']['status']);
        $this->assertSame($doc->id, $items['passport']['document_id']);
        $this->assertNotNull($items['passport']['uploaded_at']);
        $this->assertSame('not_submitted', $items['svf']['status']);
    }

    public function test_08_with_statuses_attaches_rejected_status_with_note(): void
    {
        $this->makeVisaType('Student Visa', [
            ['key' => 'passport', 'label' => 'Passport', 'required' => true],
        ]);
        $lead = $this->makeCase();
        LeadDocument::create([
            'lead_id'       => $lead->id,
            'checklist_key' => 'passport',
            'original_name' => 'passport.pdf',
            'file_path'     => 'lead-documents/x.pdf',
            'mime'          => 'application/pdf',
            'size'          => 1000,
            'status'        => LeadDocument::STATUS_REJECTED,
            'source'        => LeadDocument::SOURCE_UPLOAD,
            'note'          => 'Bio page missing.',
        ]);

        $items = collect($this->service()->withStatuses($lead))->keyBy('key');

        $this->assertSame('Rejected', $items['passport']['status']);
        $this->assertSame('Bio page missing.', $items['passport']['note']);
    }

    public function test_09_with_statuses_picks_most_recent_document_per_key(): void
    {
        $this->makeVisaType('Student Visa', [
            ['key' => 'passport', 'label' => 'Passport'],
        ]);
        $lead = $this->makeCase();

        // Auto-timestamps would clobber explicit created_at on create(),
        // so set the older row's timestamp after the fact. (Forcing
        // $timestamps = false temporarily would be uglier than this.)
        $older = LeadDocument::create([
            'lead_id'       => $lead->id,
            'checklist_key' => 'passport',
            'original_name' => 'old.pdf',
            'file_path'     => 'lead-documents/old.pdf',
            'mime'          => 'application/pdf',
            'size'          => 100,
            'status'        => LeadDocument::STATUS_REJECTED,
            'source'        => LeadDocument::SOURCE_UPLOAD,
        ]);
        $older->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        $newer = LeadDocument::create([
            'lead_id'       => $lead->id,
            'checklist_key' => 'passport',
            'original_name' => 'new.pdf',
            'file_path'     => 'lead-documents/new.pdf',
            'mime'          => 'application/pdf',
            'size'          => 200,
            'status'        => LeadDocument::STATUS_SUBMITTED,
            'source'        => LeadDocument::SOURCE_UPLOAD,
        ]);

        $items = collect($this->service()->withStatuses($lead))->keyBy('key');

        $this->assertSame($newer->id, $items['passport']['document_id']);
        $this->assertSame('Submitted', $items['passport']['status']);
    }

    public function test_10_grouped_by_category_uses_required_documents_default(): void
    {
        // JSON shape has no `category` field — service buckets under
        // "Required Documents" rather than inventing categories.
        $this->makeVisaType('Student Visa', [
            ['key' => 'passport', 'label' => 'Passport'],
            ['key' => 'ielts',    'label' => 'IELTS'],
        ]);
        $lead = $this->makeCase();

        $grouped = $this->service()->groupedByCategory($lead);

        $this->assertArrayHasKey('Required Documents', $grouped);
        $this->assertCount(2, $grouped['Required Documents']);
        $this->assertSame(['svf'], array_column($grouped['Information Form'], 'key'));
    }

    public function test_11_grouped_by_category_honours_explicit_category_field(): void
    {
        $this->makeVisaType('Student Visa', [
            ['key' => 'passport', 'label' => 'Passport', 'category' => 'Identity'],
            ['key' => 'ielts',    'label' => 'IELTS',    'category' => 'English'],
        ]);
        $lead = $this->makeCase();

        $grouped = $this->service()->groupedByCategory($lead);

        $this->assertEqualsCanonicalizing(['Information Form', 'Identity', 'English'], array_keys($grouped));
    }

    public function test_12_progress_counts_required_only(): void
    {
        $this->makeVisaType('Student Visa', [
            ['key' => 'a', 'label' => 'A', 'required' => true],
            ['key' => 'b', 'label' => 'B', 'required' => true],
            ['key' => 'c', 'label' => 'C', 'required' => false],
        ]);
        $lead = $this->makeCase();
        // Approve one required + the optional. Only the required-approved
        // counts toward the "ready for submission" number.
        LeadDocument::create([
            'lead_id'       => $lead->id, 'checklist_key' => 'a',
            'original_name' => 'a.pdf', 'file_path' => 'p/a.pdf',
            'mime' => 'application/pdf', 'size' => 1,
            'status' => 'Approved', 'source' => 'upload',
        ]);
        LeadDocument::create([
            'lead_id'       => $lead->id, 'checklist_key' => 'c',
            'original_name' => 'c.pdf', 'file_path' => 'p/c.pdf',
            'mime' => 'application/pdf', 'size' => 1,
            'status' => 'Approved', 'source' => 'upload',
        ]);

        $progress = $this->service()->progress($lead);

        // The universal information form is required even without an upload.
        $this->assertSame(3, $progress['required_total']);
        $this->assertSame(1, $progress['required_approved']);
        $this->assertSame(4, $progress['total']);
        $this->assertSame(2, $progress['approved']);
    }

    public function test_13_unstructured_documents_lists_no_checklist_key_uploads(): void
    {
        $this->makeVisaType('Student Visa', [
            ['key' => 'passport', 'label' => 'Passport'],
        ]);
        $lead = $this->makeCase();

        // Three docs — one matches the checklist (kept out of unstructured),
        // one has a key that doesn't match, one has no key at all.
        LeadDocument::create([
            'lead_id'       => $lead->id, 'checklist_key' => 'passport',
            'original_name' => 'p.pdf', 'file_path' => 'p/p.pdf',
            'mime' => 'application/pdf', 'size' => 1,
            'status' => 'Submitted', 'source' => 'upload',
        ]);
        LeadDocument::create([
            'lead_id'       => $lead->id, 'checklist_key' => 'stale_key',
            'original_name' => 'orphan-stale.pdf', 'file_path' => 'p/o1.pdf',
            'mime' => 'application/pdf', 'size' => 1,
            'status' => 'Submitted', 'source' => 'upload',
        ]);
        LeadDocument::create([
            'lead_id'       => $lead->id, 'checklist_key' => null,
            'original_name' => 'orphan-nokey.pdf', 'file_path' => 'p/o2.pdf',
            'mime' => 'application/pdf', 'size' => 1,
            'status' => 'Submitted', 'source' => 'upload',
        ]);

        $unstructured = $this->service()->unstructuredDocuments($lead);

        $this->assertCount(2, $unstructured);
        $names = collect($unstructured)->pluck('original_name')->all();
        $this->assertEqualsCanonicalizing(['orphan-stale.pdf', 'orphan-nokey.pdf'], $names);
    }

    public function test_universal_form_is_required_and_deduplicated_in_catalogue_and_overrides(): void
    {
        $items = [
            ['key' => 'passport', 'label' => 'Passport', 'required' => true],
            ['key' => 'svf', 'label' => 'Optional custom form', 'required' => false],
            ['key' => 'svf', 'label' => 'Duplicate form', 'required' => false],
        ];
        $this->makeVisaType('Student Visa', $items);

        foreach ([[], ['document_checklist' => $items]] as $attributes) {
            $lead = $this->makeCase($attributes);
            $resolved = $this->service()->forCase($lead);

            $this->assertSame(['svf', 'passport'], array_column($resolved, 'key'));
            $this->assertSame('Student Visa Information Form', $resolved[0]['label']);
            $this->assertTrue($resolved[0]['required']);
            $this->assertSame('Information Form', $resolved[0]['category']);
            $this->assertSame(2, $this->service()->progress($lead)['required_total']);
        }
    }

    public function test_universal_form_upload_has_status_and_progress_without_a_visa_catalogue(): void
    {
        $lead = $this->makeCase(['inz_visa_type' => null, 'hidden_track_documents' => ['svf']]);
        $doc = LeadDocument::create([
            'lead_id' => $lead->id, 'checklist_key' => 'svf',
            'original_name' => 'information-form.pdf', 'file_path' => 'p/form.pdf',
            'mime' => 'application/pdf', 'size' => 1,
            'status' => LeadDocument::STATUS_APPROVED, 'source' => LeadDocument::SOURCE_UPLOAD,
        ]);

        $items = $this->service()->withStatuses($lead);

        $this->assertCount(1, $items);
        $this->assertSame('svf', $items[0]['key']);
        $this->assertTrue($items[0]['required']);
        $this->assertTrue($items[0]['hidden']);
        $this->assertSame($doc->id, $items[0]['document_id']);
        $this->assertSame(LeadDocument::STATUS_APPROVED, $items[0]['status']);
        $this->assertSame([], $this->service()->unstructuredDocuments($lead));
        $this->assertSame([
            'required_total' => 1, 'required_approved' => 1, 'total' => 1, 'approved' => 1,
        ], $this->service()->progress($lead));
    }
}
