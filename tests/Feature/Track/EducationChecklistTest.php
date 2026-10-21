<?php

namespace Tests\Feature\Track;

use App\Http\Controllers\LeadTrackingController;
use App\Models\Lead;
use App\Models\LeadDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EducationChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_education_client_gets_four_required_documents_and_optional_employment(): void
    {
        $lead = Lead::create(['first_name' => 'Education', 'last_name' => 'Checklist']);
        $payload = app(LeadTrackingController::class)->buildTrackerPayload($lead, $lead->tracking_code);
        $items = collect($payload['visa']['checklist']);
        $this->assertSame(['pers.passport', 'acad.cv', 'acad.degree_diploma', 'acad.transcript'], $items->where('required', true)->pluck('key')->all());
        $this->assertSame(['pers.certificate_employment'], $items->where('required', false)->pluck('key')->values()->all());
        $this->assertNotContains('agree.proposal', $items->pluck('key'));
    }

    public function test_old_proposal_and_extra_uploads_remain_in_client_payload(): void
    {
        $lead = Lead::create(['first_name' => 'Historical', 'last_name' => 'Files']);
        foreach (['agree.proposal', 'acad.offer_of_place'] as $key) {
            LeadDocument::create(['lead_id' => $lead->id, 'checklist_key' => $key, 'original_name' => $key.'.pdf',
                'file_path' => 'private/'.$key.'.pdf', 'source' => 'upload', 'status' => 'Submitted']);
        }
        $payload = app(LeadTrackingController::class)->buildTrackerPayload($lead, $lead->tracking_code);
        $this->assertEqualsCanonicalizing(['agree.proposal', 'acad.offer_of_place'], collect($payload['documents'])->pluck('checklist_key')->all());
    }
}
