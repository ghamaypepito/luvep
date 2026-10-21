<?php

namespace Tests\Feature\Immigration;

use App\Http\Controllers\LeadTrackingController;
use App\Models\{Lead, LeadDocument, VisaType};
use App\Services\Immigration\CaseChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerVisaChecklistAliasTest extends TestCase
{
    use RefreshDatabase;

    private function makeCase(string $code = 'PRSWV'): Lead
    {
        VisaType::create(['code' => $code, 'name' => 'Visa '.$code, 'category' => 'Partner', 'active' => true,
            'consultation_price_nzd' => 250, 'checklist_items' => [
                ['key' => 'sponsor_nz_visa', 'label' => 'Copy of the most recent NZ visa of the Sponsor', 'required' => true],
                ['key' => 'nz_visa', 'label' => 'NZ Visa of Partner', 'required' => true],
            ]]);
        return Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Applicant', 'is_immigration_case' => true, 'inz_visa_type' => $code]);
    }

    private function upload(Lead $lead, string $key): LeadDocument
    {
        return LeadDocument::create(['lead_id' => $lead->id, 'checklist_key' => $key, 'source' => 'upload',
            'original_name' => $key.'.pdf', 'file_path' => 'synthetic/'.$key.'.pdf', 'status' => 'Approved']);
    }

    public function test_staff_and_client_have_one_requirement_with_both_historical_files(): void
    {
        $lead = $this->makeCase();
        $this->upload($lead, 'sponsor_nz_visa');
        $alias = $this->upload($lead, 'nz_visa');
        $alias->forceFill(['created_at' => now()->addMinute()])->save();
        $service = app(CaseChecklistService::class);
        $items = collect($service->withStatuses($lead));
        $this->assertSame(['svf', 'sponsor_nz_visa'], $items->pluck('key')->all());
        $this->assertSame(['nz_visa'], $items->last()['aliases']);
        $this->assertSame($alias->id, $items->last()['document_id']);
        $this->assertSame([], $service->unstructuredDocuments($lead));
        $payload = app(LeadTrackingController::class)->buildTrackerPayload($lead, $lead->tracking_code);
        $row = collect($payload['visa']['checklist'])->firstWhere('key', 'sponsor_nz_visa');
        $this->assertSame(2, $row['count']);
        $this->assertSame(['nz_visa'], $row['aliases']);
        $this->assertCount(2, $payload['documents']);
        $this->assertSame(2, $lead->documents()->count());
    }

    public function test_hidden_alias_hides_the_requirement_and_both_files_from_client(): void
    {
        $lead = $this->makeCase();
        $this->upload($lead, 'sponsor_nz_visa');
        $this->upload($lead, 'nz_visa');
        $lead->update(['hidden_track_documents' => ['nz_visa']]);
        $this->assertTrue(collect(app(CaseChecklistService::class)->withStatuses($lead))->last()['hidden']);
        $payload = app(LeadTrackingController::class)->buildTrackerPayload($lead, $lead->tracking_code);
        $this->assertSame(['svf'], collect($payload['visa']['checklist'])->pluck('key')->all());
        $this->assertCount(0, $payload['documents']);
        $this->assertSame(2, $lead->documents()->count());
    }

    public function test_hidden_and_cross_account_upload_streams_are_denied(): void
    {
        $lead = $this->makeCase();
        $doc = $this->upload($lead, 'nz_visa');
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put($doc->file_path, 'synthetic');
        $client = \App\Models\User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id]);
        $this->actingAs($client)->get("/portal/lead/documents/{$doc->id}/download")->assertOk();
        $other = Lead::create(['first_name' => 'Unrelated', 'last_name' => 'Client']);
        $outsider = \App\Models\User::factory()->create(['role' => 'lead', 'lead_id' => $other->id]);
        $this->actingAs($outsider)->get("/portal/lead/documents/{$doc->id}/download")->assertForbidden();
        $lead->update(['hidden_track_documents' => ['sponsor_nz_visa']]);
        $this->actingAs($client)->get("/portal/lead/documents/{$doc->id}/download")->assertNotFound();
    }

    public function test_showing_canonical_requirement_clears_historical_alias_hidden_flag(): void
    {
        $lead = $this->makeCase();
        $lead->update(['hidden_track_documents' => ['nz_visa']]);
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post("/admin/leads/{$lead->id}/documents/track-visibility", [
            'checklist_keys' => ['sponsor_nz_visa'], 'hidden' => false,
        ])->assertRedirect();
        $this->assertSame([], $lead->fresh()->hidden_track_documents);
    }

    public function test_canonical_only_override_still_reads_and_hides_historical_alias_files(): void
    {
        $lead = $this->makeCase();
        $lead->update(['document_checklist' => [['key' => 'sponsor_nz_visa', 'label' => 'Sponsor visa', 'required' => true]],
            'hidden_track_documents' => ['nz_visa']]);
        $doc = $this->upload($lead, 'nz_visa');
        $service = app(CaseChecklistService::class);
        $row = collect($service->withStatuses($lead))->last();
        $this->assertSame($doc->id, $row['document_id']);
        $this->assertTrue($row['hidden']);
        $this->assertSame([], $service->unstructuredDocuments($lead));
    }

    public function test_other_visas_keep_distinct_requirements(): void
    {
        $lead = $this->makeCase('OTHER');
        $this->assertSame(['svf', 'sponsor_nz_visa', 'nz_visa'], array_column(app(CaseChecklistService::class)->forCase($lead), 'key'));
    }
}
