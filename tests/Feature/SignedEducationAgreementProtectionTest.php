<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SignedEducationAgreementProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_agreements_cannot_be_repriced_or_signed_again(): void
    {
        Storage::fake('local');
        $staff = User::factory()->create(['role' => 'education']);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Applicant']);

        foreach (['agree.consultancy', 'agree.engagement_english'] as $key) {
            foreach (['client_signed_at', 'client_signature_path'] as $marker) {
                $doc = LeadDocument::create([
                    'lead_id' => $lead->id, 'checklist_key' => $key,
                    'source' => LeadDocument::SOURCE_GENERATED,
                    'source_variant' => 'consultancy:std_100:single',
                    'original_name' => 'agreement.pdf', 'file_path' => uniqid('agreements/').'.pdf',
                    'mime' => 'application/pdf', 'size' => 16,
                    $marker => $marker === 'client_signed_at' ? now() : uniqid('signatures/').'.png',
                ]);
                Storage::disk('local')->put($doc->file_path, 'Original signed agreement');
                if ($doc->client_signature_path) {
                    Storage::disk('local')->put($doc->client_signature_path, 'Original signature');
                }
                $before = $doc->fresh()->getAttributes();

                $this->actingAs($staff)->post('/admin/leads/'.$lead->id.'/documents/'.$doc->id.'/regenerate')->assertStatus(409);
                $this->post('/track/'.$lead->tracking_code.'/documents/'.$doc->id.'/sign', [
                    'acknowledged' => true, 'signature_data' => 'replacement', 'signer_name' => 'Replacement',
                ])->assertStatus(409);

                $this->assertSame($before, $doc->fresh()->getAttributes());
                $this->assertSame('Original signed agreement', Storage::disk('local')->get($doc->file_path));
                if ($doc->client_signature_path) {
                    $this->assertSame('Original signature', Storage::disk('local')->get($doc->client_signature_path));
                }
            }
        }
    }
}
