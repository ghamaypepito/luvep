<?php

namespace Tests\Feature\Track;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Models\VisaType;
use Database\Seeders\VisaChecklistSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Client upload, checklist status and staff-review integration.
 * Immigration cases use the authenticated client portal; their public
 * tracking codes never grant access to case details or uploads.
 */
class UploadChecklistTest extends TestCase
{
    use RefreshDatabase;

    private array $clients = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function client(Lead $lead): User
    {
        return $this->clients[$lead->id] ??= User::factory()->create([
            'role' => 'lead', 'lead_id' => $lead->id, 'email' => $lead->email,
        ]);
    }

    private function viewApplication(Lead $lead)
    {
        return $this->actingAs($this->client($lead))->get('/portal/lead/tracker');
    }

    private function uploadChecklist(Lead $lead, string $key)
    {
        return $this->actingAs($this->client($lead))
            ->post("/portal/lead/documents/checklist/{$key}/upload", [
                'files' => [UploadedFile::fake()->create('document.pdf', 200, 'application/pdf')],
            ])->assertSessionHasNoErrors()->assertRedirect();
    }

    /** A minimal admin user used for the staff-approval call. */
    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Seed a complete Student Visa catalogue row + its checklist, exactly
     * as production would have after VisaTypeSeeder + VisaChecklistSeeder.
     */
    private function seedStudentVisa(): VisaType
    {
        VisaType::create([
            'code'                          => 'STUDENT',
            'name'                          => 'Student Visa',
            'category'                      => 'Student',
            'short_description'             => 'Test',
            'consultation_price_nzd'        => 150.00,
            'consultation_duration_minutes' => 30,
            'estimated_minutes'             => 10,
            'icon'                          => 'GraduationCap',
            'active'                        => true,
        ]);

        $this->seed(VisaChecklistSeeder::class);

        return VisaType::where('code', 'STUDENT')->firstOrFail();
    }

    private function makeImmigrationLead(array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'lead_id'             => 'LP-IMM-1',
            'first_name'          => 'Aroha',
            'last_name'           => 'Tane',
            'email'               => 'aroha@example.com',
            'is_immigration_case' => true,
            'inz_visa_type'       => 'Student Visa',
            'status'              => 'New Leads',
        ], $overrides));
    }

    // ─── visa.checklist payload (covers spec tests 4, 5, 6) ──────────────

    public function test_04_track_payload_carries_full_visa_checklist_for_immigration_case(): void
    {
        $this->seedStudentVisa();
        $lead = $this->makeImmigrationLead();

        $this->viewApplication($lead)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('visa.code', 'STUDENT')
                ->where('visa.name', 'Student Visa')
                ->has('visa.checklist', 33)
                ->where('visa.checklist.0.key', 'svf')
                ->where('visa.checklist.0.required', true)
                ->where('visa.totals.approved', 0)
                ->where('visa.totals.submitted', 0)
            );
    }

    public function test_04b_each_checklist_item_carries_the_shape_the_picker_consumes(): void
    {
        $this->seedStudentVisa();
        $lead = $this->makeImmigrationLead();

        $this->viewApplication($lead)
            ->assertInertia(fn ($page) => $page
                ->where('visa.checklist', function ($items) {
                    foreach ($items as $item) {
                        foreach (['key', 'label', 'hint', 'required', 'status', 'count'] as $field) {
                            $this->assertArrayHasKey($field, $item);
                        }
                        $this->assertIsBool($item['required']);
                        $this->assertSame('missing', $item['status']);
                        $this->assertSame(0, $item['count']);
                    }
                    // Modern catalogue rows carry their section separately;
                    // the picker also accepts the universal information form.
                    $passport = collect($items)->firstWhere('key', 'passport');
                    $this->assertSame('Applicant Documents', $passport['category']);
                    $this->assertSame('Passport (PDF format)', $passport['label']);

                    return true;
                })
            );
    }

    public function test_05_public_non_immigration_lead_gets_education_intake_requirements(): void
    {
        $lead = Lead::create([
            'lead_id'             => 'LP-NORMAL-1',
            'first_name'          => 'Sam',
            'last_name'           => 'Smith',
            'email'               => 'sam@example.com',
            'is_immigration_case' => false,
            'status'              => 'New Leads',
        ]);

        $this->get("/track/{$lead->tracking_code}")
            ->assertInertia(fn ($page) => $page
                ->where('visa.code', null)
                ->where('visa.name', 'Document Checklist')
                ->where('visa.checklist', fn ($items) => collect($items)->pluck('key')->all() === [
                    'pers.passport', 'acad.cv', 'acad.degree_diploma',
                    'acad.transcript', 'pers.certificate_employment',
                ])
                ->where('lead.is_immigration_case', false)
            );
    }

    public function test_06_immigration_lead_without_a_visa_type_gets_general_requirements(): void
    {
        $this->seedStudentVisa();
        $lead = $this->makeImmigrationLead(['inz_visa_type' => null]);

        $this->viewApplication($lead)
            ->assertInertia(fn ($page) => $page
                ->where('visa.code', null)
                ->where('visa.name', 'Document Checklist')
                ->where('visa.checklist', fn ($items) => collect($items)->contains('key', 'pers.passport'))
            );
    }

    public function test_06b_immigration_lead_with_unknown_visa_type_still_renders_with_empty_checklist(): void
    {
        // Lead points at a visa we don't catalogue. resolveVisa() returns a
        // bare envelope so the panel still shows the visa name without
        // breaking; the checklist is empty so the modal still falls back
        // to DOC_TYPES (useChecklist evaluates false).
        $lead = $this->makeImmigrationLead(['inz_visa_type' => 'Made-Up Visa']);

        $this->viewApplication($lead)
            ->assertInertia(fn ($page) => $page
                ->where('visa.name', 'Made-Up Visa')
                ->has('visa.checklist', 0)
            );
    }

    // ─── upload sets checklist_key (covers spec test 7) ──────────────────

    public function test_07_uploading_sets_lead_document_checklist_key_to_the_picked_item(): void
    {
        $this->seedStudentVisa();
        $lead = $this->makeImmigrationLead();

        $this->uploadChecklist($lead, 'passport');

        $doc = LeadDocument::where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame('passport', $doc->checklist_key);
        $this->assertSame(LeadDocument::STATUS_SUBMITTED, $doc->status);
        $this->assertSame($this->client($lead)->id, $doc->uploaded_by);
        Storage::disk('local')->assertExists($doc->file_path);
        Storage::disk('public')->assertMissing($doc->file_path);
    }

    // ─── status flips Missing → Submitted (covers spec test 8) ──────────

    public function test_08_uploaded_doc_flips_matching_checklist_item_to_submitted(): void
    {
        $this->seedStudentVisa();
        $lead = $this->makeImmigrationLead();

        // Item starts missing.
        $this->viewApplication($lead)
            ->assertInertia(fn ($page) => $page->where(
                'visa.checklist',
                fn ($items) => collect($items)->firstWhere('key', 'passport')['status'] === 'missing'
            ));

        // Upload one against passport.
        $this->uploadChecklist($lead, 'passport');

        // Item now shows submitted, totals.submitted incremented.
        $this->viewApplication($lead)
            ->assertInertia(fn ($page) => $page
                ->where(
                    'visa.checklist',
                    fn ($items) => collect($items)->firstWhere('key', 'passport')['status'] === 'submitted'
                )
                ->where('visa.totals.submitted', 1)
                ->where('visa.totals.approved', 0)
            );
    }

    public function test_08b_upload_to_unknown_checklist_key_does_not_flip_any_item(): void
    {
        // If the lead uses the "other" fallback, no checklist item should
        // gain status — the doc just lives outside the requirements panel.
        $this->seedStudentVisa();
        $lead = $this->makeImmigrationLead();

        $this->uploadChecklist($lead, 'other');

        $this->viewApplication($lead)
            ->assertInertia(fn ($page) => $page
                ->where('visa.totals.submitted', 0)
                ->where('visa.totals.approved', 0)
            );
    }

    // ─── status flips Submitted → Approved (covers spec test 9) ─────────

    public function test_09_staff_approval_flips_checklist_item_to_approved_on_track(): void
    {
        $this->seedStudentVisa();
        $lead = $this->makeImmigrationLead();

        // Lead uploads.
        $this->uploadChecklist($lead, 'passport');
        $doc = LeadDocument::where('lead_id', $lead->id)->firstOrFail();

        // Staff approves via the admin documents route.
        $this->actingAs($this->admin())
            ->post("/admin/leads/{$lead->id}/documents/{$doc->id}/status", ['status' => 'Approved'])
            ->assertRedirect();

        // Visa Requirements panel now shows the item as approved with
        // totals.approved=1.
        $this->viewApplication($lead)
            ->assertInertia(fn ($page) => $page
                ->where(
                    'visa.checklist',
                    fn ($items) => collect($items)->firstWhere('key', 'passport')['status'] === 'approved'
                )
                ->where('visa.totals.approved', 1)
                ->where('visa.totals.submitted', 1)
            );
    }

    // ─── New tabbed-layout coverage (servable-testable subset) ──────────

    public function test_10_client_tracker_renders_with_its_authenticated_layout(): void
    {
        // The client application stays within the authenticated portal chrome.
        $this->seedStudentVisa();
        $lead = $this->makeImmigrationLead();

        $this->viewApplication($lead)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('portal/lead/Tracker')->where('embedded', true));
    }

    public function test_11_track_page_payload_carries_data_for_every_tab(): void
    {
        // The tab strip is client-only React state; the page needs all
        // four data prop families (lead/info, documents, visa.checklist,
        // timeline) to be present so any tab the user lands on renders.
        $this->seedStudentVisa();
        $lead = $this->makeImmigrationLead();

        $this->viewApplication($lead)
            ->assertInertia(fn ($page) => $page
                ->has('lead')
                ->has('info')
                ->has('documents')
                ->has('visa.checklist')
                ->has('timeline')
            );
    }

    public function test_12_journey_carries_completed_current_and_pending_steps(): void
    {
        // The JourneySnapshot picks the last completed + first current +
        // next pending out of the timeline. The server must emit a
        // timeline shape that supports this calculation.
        $this->seedStudentVisa();
        $lead = $this->makeImmigrationLead([
            'date_of_first_contact' => now()->subDays(5),
            'date_of_engagement'    => now()->subDays(3),
        ]);

        $this->viewApplication($lead)
            ->assertInertia(fn ($page) => $page
                ->where('timeline', fn ($timeline) => collect($timeline)->contains(fn ($step) => $step['status'] === 'completed'))
                ->where('timeline', fn ($timeline) => collect($timeline)->contains(fn ($step) => $step['status'] === 'current'))
                ->where('timeline', fn ($timeline) => collect($timeline)->contains(fn ($step) => $step['status'] === 'pending'))
            );
    }

    public function test_13_journey_first_step_is_current_when_nothing_started(): void
    {
        // Edge case for the snapshot's COMPLETED card: a brand-new lead
        // has no completed step yet, so the snapshot should fall back to
        // the "Application started" empty state. The server signal is
        // that no step in the timeline carries status='completed'.
        $this->seedStudentVisa();
        $lead = $this->makeImmigrationLead([
            'date_of_first_contact' => null,
            'date_of_engagement'    => null,
        ]);

        $this->viewApplication($lead)
            ->assertInertia(fn ($page) => $page
                ->where('timeline.0.status', 'current')
                ->where('timeline', fn ($timeline) => collect($timeline)->doesntContain(fn ($step) => $step['status'] === 'completed'))
            );
    }

    public function test_14_declined_outcome_appends_alternative_step_to_journey(): void
    {
        // Terminal state: when the case is declined, the journey
        // snapshot replaces the trio with a single Declined card. The
        // server signal is an extra step at the end of the timeline
        // tagged with the 'alternative' marker.
        $this->seedStudentVisa();
        $lead = $this->makeImmigrationLead([
            'immigration_stage' => 'Decline Visa',
            'inz_decision_at'   => now()->subDay(),
        ]);

        $this->viewApplication($lead)
            ->assertInertia(fn ($page) => $page
                ->where('timeline', fn ($timeline) =>
                    collect($timeline)->last()['key'] === 'application_declined'
                )
            );
    }

    public function test_public_case_code_never_exposes_case_data_or_accepts_uploads(): void
    {
        $lead = $this->makeImmigrationLead();

        $this->get("/track/{$lead->tracking_code}")
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page
                ->where('lead', null)->where('info', null)
                ->has('documents', 0)->where('visa', null)
            );
        $this->assertNull($lead->fresh()->last_seen_at);

        $this->post("/track/{$lead->tracking_code}/document", [
            'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
            'checklist_key' => 'passport',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseCount('lead_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_client_tracker_and_uploads_stay_scoped_to_their_own_case(): void
    {
        $this->seedStudentVisa();
        $own = $this->makeImmigrationLead();
        $other = $this->makeImmigrationLead([
            'lead_id' => 'LP-IMM-2', 'first_name' => 'Other', 'email' => 'other@example.test',
        ]);
        $this->uploadChecklist($other, 'passport');

        $this->actingAs($this->client($own))
            ->get("/portal/lead/tracker?lead_id={$other->id}&code={$other->tracking_code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lead.tracking_code', $own->tracking_code)->has('documents', 0)
                ->where('visa.checklist', fn ($items) => collect($items)->firstWhere('key', 'passport')['count'] === 0)
            );

        $this->post('/portal/lead/documents/checklist/passport/upload', [
            'lead_id' => $other->id,
            'files' => [UploadedFile::fake()->create('own-passport.pdf', 100, 'application/pdf')],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $own->documents()->count());
        $this->assertSame(1, $other->documents()->count());
        $this->assertSame($this->client($own)->id, $own->documents()->firstOrFail()->uploaded_by);
    }
}
