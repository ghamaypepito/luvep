<?php

namespace Tests\Feature\Immigration;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadDocumentRequest;
use App\Models\User;
use App\Models\VisaType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NbiClientPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        Mail::fake();
        Notification::fake();
        Bus::fake();
        Http::fake();
    }

    public static function slots(): array
    {
        return [['SMCRV', 'police_clearance'], ['SMCRV1', 'police_clearance'], ['PRSWV', 'police_nbi_clearance'], ['AEWV', 'police_certificate'], ['WORK_AEWV', 'police_certificate']];
    }

    private function client(string $code, string $key): array
    {
        $visa = VisaType::create(['code' => $code, 'name' => 'Synthetic '.$code, 'category' => 'Resident', 'checklist_items' => [['key' => $key, 'label' => 'Existing clearance', 'required' => true], ['key' => 'other', 'label' => 'Other document']]]);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'NBI', 'inz_visa_type' => $visa->name, 'is_immigration_case' => true, 'portal_invitation_status' => 'accepted', 'tracking_code' => 'NBI'.uniqid()]);
        $user = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id]);
        $this->actingAs($user);

        return [$lead, $user];
    }

    private function realFile(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'nbi161-');
        file_put_contents($path, $bytes);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function image(string $name): UploadedFile
    {
        return $this->realFile($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
    }

    private function pdf(): UploadedFile
    {
        return $this->realFile('clearance.pdf', "%PDF-1.4\n1 0 obj<</Type /Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
    }

    #[DataProvider('slots')]
    public function test_supported_slots_accept_real_pdf_and_reject_images_and_disguises(string $code, string $key): void
    {
        [$lead] = $this->client($code, $key);
        foreach (['clearance.png', 'disguised.pdf'] as $name) {
            $this->postJson('/portal/lead/documents/checklist/'.$key.'/upload', ['files' => [$this->image($name)]])->assertUnprocessable()->assertJsonValidationErrors('files.0');
        }$this->assertDatabaseCount('lead_documents', 0);
        $this->post('/portal/lead/documents/checklist/'.$key.'/upload', ['files' => [$this->pdf()]])->assertRedirect()->assertSessionHasNoErrors();
        $doc = LeadDocument::sole();
        $this->assertSame('Submitted', $doc->status);
        $this->assertSame($key, $doc->checklist_key);
        Storage::disk('local')->assertExists($doc->file_path);
        $this->actingAs(User::factory()->create(['role' => 'lead', 'lead_id' => Lead::create(['first_name' => 'Other', 'last_name' => 'Client'])->id]))->get('/portal/lead/documents/'.$doc->id.'/download')->assertForbidden();
    }

    public function test_failed_replacement_preserves_existing_image_then_pdf_replacement_enters_review(): void
    {
        [$lead] = $this->client('SMCRV', 'police_clearance');
        Storage::disk('local')->put('old/clearance.png', 'historical image bytes');
        $doc = LeadDocument::create(['lead_id' => $lead->id, 'checklist_key' => 'police_clearance', 'file_path' => 'old/clearance.png', 'original_name' => 'old.png', 'mime' => 'image/png', 'status' => 'Rejected']);
        $before = $doc->refresh()->getRawOriginal();
        $this->postJson('/portal/lead/documents/'.$doc->id.'/replace', ['file' => $this->image('fake.pdf')])->assertUnprocessable();
        $this->assertSame($before, $doc->refresh()->getRawOriginal());
        Storage::disk('local')->assertExists('old/clearance.png');
        $this->post('/portal/lead/documents/'.$doc->id.'/replace', ['file' => $this->pdf()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Submitted', $doc->refresh()->status);
        $this->assertSame('application/pdf', $doc->mime);
    }

    public function test_request_policy_uses_existing_binding_not_forged_input_or_label(): void
    {
        [$lead] = $this->client('SMCRV', 'police_clearance');
        $req = LeadDocumentRequest::create(['lead_id' => $lead->id, 'label' => 'Arbitrary text', 'requested_at' => now()]);
        LeadDocument::create(['lead_id' => $lead->id, 'request_id' => $req->id, 'checklist_key' => 'police_clearance', 'original_name' => 'old.pdf', 'file_path' => 'old/file.pdf', 'status' => 'Rejected']);
        $this->postJson('/portal/lead/documents/upload', ['request_id' => $req->id, 'checklist_key' => 'other', 'file' => $this->image('nbi.pdf')])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post('/portal/lead/documents/upload', ['request_id' => $req->id, 'file' => $this->pdf()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('police_clearance', LeadDocument::latest('id')->first()->checklist_key);
        $unbound = LeadDocumentRequest::create(['lead_id' => $lead->id, 'label' => 'NBI Police Clearance', 'requested_at' => now()]);
        $this->post('/portal/lead/documents/upload', ['request_id' => $unbound->id, 'file' => $this->image('allowed.png')])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/portal/lead/documents/checklist/other/upload', ['files' => [$this->image('other.png')]])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_pdf_hint_is_scoped_to_resolved_catalogue_and_existing_request_binding(): void
    {
        [$lead] = $this->client('SMCRV', 'police_clearance');
        $this->get('/portal/lead/documents')->assertInertia(fn ($page) => $page->where('checklist.Required Documents.0.client_pdf_only', true)->where('checklist.Required Documents.1.client_pdf_only', false));
        $this->post('/track/'.$lead->tracking_code.'/document', ['checklist_key' => 'police_clearance', 'file' => $this->pdf()])->assertRedirect();
    }

    public function test_aliases_require_pdf_but_unmatched_visas_and_removed_slots_keep_the_existing_policy(): void
    {
        [$lead] = $this->client('SMCRV', 'police_clearance');
        $lead->update(['document_checklist' => [['key' => 'clearance_alias', 'label' => 'Custom name', 'aliases' => ['police_clearance']]]]);
        $this->postJson('/portal/lead/documents/checklist/clearance_alias/upload', ['files' => [$this->image('disguised.pdf')]])->assertUnprocessable();
        $this->postJson('/portal/lead/documents/checklist/police_clearance/upload', ['files' => [$this->realFile('clearance.png', $this->pdf()->getContent())]])->assertUnprocessable();
        $lead->update(['document_checklist' => [['key' => 'other', 'label' => 'NBI Police Clearance']]]);
        $this->post('/portal/lead/documents/checklist/other/upload', ['files' => [$this->image('normal.png')]])->assertRedirect()->assertSessionHasNoErrors();
        $visa = VisaType::where('name', $lead->inz_visa_type)->sole();
        $visa->update(['code' => 'STUDENT']);
        $lead->update(['document_checklist' => null]);
        $this->post('/portal/lead/documents/checklist/police_clearance/upload',['files' => [$this->image('normal.png')]])->assertRedirect()->assertSessionHasNoErrors();
    }
}
