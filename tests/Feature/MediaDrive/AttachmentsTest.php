<?php

namespace Tests\Feature\MediaDrive;

use App\Models\CaseDependent;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadDocumentRequest;
use App\Models\LeadNote;
use App\Models\MediaFile;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('tenant_private');
        Mail::fake();
        Notification::fake();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function client(): Lead
    {
        return Lead::create(['first_name' => 'Media', 'last_name' => 'Client', 'email' => Str::uuid().'@example.test']);
    }

    private function asset($client, string $type = 'lead'): MediaFile
    {
        $folder = $this->postJson('/media-drive/api/folders', ['client_type' => $type, 'client_id' => $client->id, 'name' => 'Client files'])->assertCreated()->json('folder.id');
        $id = $this->postJson("/media-drive/api/folders/{$folder}/files", ['upload_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('sample.pdf', 5, 'application/pdf')])->assertCreated()->json('file.id');

        return MediaFile::findOrFail($id);
    }

    public function test_attachment_is_a_reference_preserves_existing_review_and_is_idempotent_per_target(): void
    {
        $client = $this->client();
        $file = $this->asset($client);
        $data = ['client_type' => 'lead', 'client_id' => $client->id, 'file_ids' => [$file->id], 'checklist_key' => 'passport'];
        $this->postJson('/media-drive/api/attach', $data)->assertOk()->assertJsonPath('attached', 1);
        $doc = LeadDocument::sole();
        $doc->update(['status' => 'Approved', 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
        $before = $doc->fresh()->getRawOriginal();
        $this->postJson('/media-drive/api/attach', $data)->assertOk()->assertJsonPath('attached', 0);
        $this->assertSame($before, $doc->fresh()->getRawOriginal());
        $this->assertSame($file->file_path, $doc->file_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->postJson('/media-drive/api/attach', [...$data, 'checklist_key' => 'identity'])->assertOk()->assertJsonPath('attached', 1);
        $this->assertSame('Submitted', LeadDocument::where('checklist_key', 'identity')->sole()->status);
        $this->assertSame('Approved', $doc->fresh()->status);
    }

    public function test_foreign_client_file_and_request_or_dependent_ids_cannot_be_attached(): void
    {
        $client = $this->client();
        $other = $this->client();
        $file = $this->asset($other);
        $data = ['client_type' => 'lead', 'client_id' => $client->id, 'file_ids' => [$file->id], 'checklist_key' => 'passport'];
        $this->postJson('/media-drive/api/attach', $data)->assertUnprocessable();
        $file = $this->asset($client);
        $data['file_ids'] = [$file->id];
        $request = LeadDocumentRequest::create(['lead_id' => $other->id, 'label' => 'Other request', 'requested_by' => auth()->id(), 'requested_at' => now()]);
        $dependent = CaseDependent::create(['lead_id' => $other->id, 'first_name' => 'Other', 'family_name' => 'Dependent', 'relationship' => 'child']);
        $this->postJson('/media-drive/api/attach', [...$data, 'request_id' => $request->id])->assertUnprocessable();
        $this->postJson('/media-drive/api/attach', [...$data, 'dependent_id' => $dependent->id])->assertUnprocessable();
        $this->assertDatabaseCount('lead_documents', 0);
    }

    public function test_deleting_a_document_reference_preserves_the_drive_file_and_its_download(): void
    {
        $client = $this->client();
        $file = $this->asset($client);
        $this->postJson('/media-drive/api/attach', ['client_type' => 'lead', 'client_id' => $client->id, 'file_ids' => [$file->id]])->assertOk();
        $doc = LeadDocument::sole();
        $this->delete("/admin/leads/{$client->id}/documents/{$doc->id}")->assertRedirect();
        $this->assertDatabaseCount('lead_documents', 0);
        Storage::disk('local')->assertExists($file->file_path);
        $this->get('/media-drive/api/files/'.$file->id.'/download')->assertDownload('sample.pdf');
    }

    public function test_client_replacement_keeps_the_original_drive_asset_and_detaches_the_new_version(): void
    {
        $client = $this->client();
        $file = $this->asset($client);
        $this->postJson('/media-drive/api/attach', ['client_type' => 'lead', 'client_id' => $client->id, 'file_ids' => [$file->id], 'checklist_key' => 'passport'])->assertOk();
        $doc = LeadDocument::sole();
        $user = User::factory()->create(['role' => 'lead', 'lead_id' => $client->id]);
        $this->actingAs($user)->post('/portal/lead/documents/'.$doc->id.'/replace', ['file' => UploadedFile::fake()->create('replacement.pdf', 6, 'application/pdf')])->assertRedirect()->assertSessionHasNoErrors();
        $doc->refresh();
        $this->assertNull($doc->media_file_id);
        $this->assertNotSame($file->file_path, $doc->file_path);
        Storage::disk('local')->assertExists($file->file_path);
    }

    public function test_notes_can_reference_drive_assets_without_copying_or_leaking_another_clients_file(): void
    {
        $client = $this->client();
        $other = $this->client();
        $file = $this->asset($client);
        $this->post('/admin/leads/'.$client->id.'/notes', ['body' => 'Attached from Media Drive', 'media_file_ids' => [$file->id]])->assertRedirect()->assertSessionHasNoErrors();
        $note = LeadNote::sole();
        $this->assertSame($file->id, $note->attachments[0]['media_file_id']);
        $this->get("/admin/leads/{$client->id}/notes/{$note->id}/attachments/0")->assertDownload('sample.pdf');
        $this->post('/admin/leads/'.$other->id.'/notes', ['body' => 'Forbidden reference', 'media_file_ids' => [$file->id]])->assertSessionHasErrors('media_file_ids');
        $this->assertDatabaseCount('lead_notes', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_tenant_attachment_uses_the_same_private_bytes_and_existing_tenant_download(): void
    {
        $property = Property::create(['name' => 'House', 'rent_single' => 100, 'status' => 'available']);
        $tenant = Tenant::create(['property_id' => $property->id, 'first_name' => 'Tenant', 'family_name' => 'Sample', 'contract_type' => 'fixed_term']);
        $file = $this->asset($tenant, 'tenant');
        $data = ['client_type' => 'tenant', 'client_id' => $tenant->id, 'file_ids' => [$file->id], 'category' => 'passport'];
        $this->postJson('/media-drive/api/attach', $data)->assertOk()->assertJsonPath('attached', 1);
        $this->postJson('/media-drive/api/attach', $data)->assertOk()->assertJsonPath('attached', 0);
        $doc = $tenant->documents()->sole();
        $this->assertSame($file->id, $doc->media_file_id);
        $this->get("/portal/accommodation/tenants/{$tenant->id}/documents/{$doc->id}")->assertDownload('sample.pdf');
        $this->assertSame([], Storage::disk('tenant_private')->allFiles());
    }

    public function test_drive_backed_documents_keep_record_authorization_on_legacy_download_routes(): void
    {
        $client = $this->client();
        $client->update(['is_immigration_case' => true]);
        $file = $this->asset($client);
        $this->postJson('/media-drive/api/attach', ['client_type' => 'lead', 'client_id' => $client->id, 'file_ids' => [$file->id]])->assertOk();
        $doc = LeadDocument::sole();
        $this->actingAs(User::factory()->create(['role' => 'education']))->get('/admin/documents/'.$doc->id.'/download')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']))->get('/admin/documents/'.$doc->id.'/download')->assertDownload('sample.pdf');
    }
}
