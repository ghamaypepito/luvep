<?php

namespace Tests\Feature\MediaDrive;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadDocumentRequest;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\User;
use App\Models\VisaType;
use App\Services\MediaDrive\UploadNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class UploadNamesTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        $this->staff = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->staff);
    }

    private function client(): Lead
    {
        return Lead::create(['first_name' => 'Example', 'last_name' => 'Client', 'email' => Str::uuid().'@example.test', 'is_immigration_case' => true]);
    }

    private function bytes(): string
    {
        return "%PDF-1.4\nExact binary source\x00\xFF\n%%EOF";
    }

    private function upload(?string $bytes = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('renamed-local-copy.pdf', $bytes ?? $this->bytes());
    }

    private function asset(Lead $lead, string $name = 'Original Drive.pdf', bool $nested = false): MediaFile
    {
        $root = MediaFolder::firstOrCreate(['client_key' => 'lead:'.$lead->id], ['lead_id' => $lead->id, 'name' => 'Client files', 'ancestry' => '/']);
        $folder = $nested ? MediaFolder::create(['parent_id' => $root->id, 'root_id' => $root->id, 'name' => 'Subfolder', 'ancestry' => '/'.$root->id.'/']) : $root;
        $path = 'media-drive/assets/'.$root->id.'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $this->bytes());

        return MediaFile::create(['folder_id' => $folder->id, 'original_name' => $name, 'file_path' => $path, 'mime' => 'application/pdf', 'size' => strlen($this->bytes()), 'sha256' => hash('sha256', $this->bytes()), 'upload_key' => (string) Str::uuid(), 'actor_key' => 'user:'.$this->staff->id, 'uploaded_by' => $this->staff->id, 'initial_status' => 'StaffShared']);
    }

    public function test_staff_checklist_upload_recovers_drive_name_and_keeps_each_copy_private_and_unique(): void
    {
        $lead = $this->client();
        $lead->update(['inz_visa_type' => 'Filename Visa']);
        VisaType::create(['code' => 'FILENAME', 'name' => 'Filename Visa', 'category' => 'Test', 'active' => true, 'checklist_items' => [['key' => 'passport', 'label' => 'Passport', 'file_code' => 'PASS']]]);
        $asset = $this->asset($lead, 'Canonical Passport.pdf', true);
        $before = $asset->fresh()->getRawOriginal();
        for ($i = 0; $i < 2; $i++) {
            $this->post('/admin/leads/'.$lead->id.'/documents/checklist/passport/upload', ['files' => [$this->upload()]])->assertRedirect()->assertSessionHasNoErrors();
        }
        $docs = LeadDocument::where('lead_id', $lead->id)->get();
        $this->assertSame(['Canonical Passport.pdf', 'Canonical Passport.pdf'], $docs->pluck('original_name')->all());
        $this->assertCount(2, $docs->pluck('file_path')->unique());
        foreach ($docs as $doc) {
            $this->assertStringStartsWith('lead-documents/'.$lead->id.'/', $doc->file_path);
            $this->assertNotSame($asset->file_path, $doc->file_path);
            $this->assertSame($this->bytes(), Storage::disk('local')->get($doc->file_path));
            $this->assertSame('Submitted', $doc->status);
            $this->assertNull($doc->media_file_id);
            $this->assertNull($doc->reviewed_at);
        }
        $this->assertSame($before, $asset->fresh()->getRawOriginal());
        $this->assertCount(3, Storage::disk('local')->allFiles());
    }

    public function test_other_upload_recovers_name_from_the_case_root(): void
    {
        $lead = $this->client();
        $this->asset($lead);
        $this->post('/portal/immigration/cases/'.$lead->id.'/documents/other', ['files' => [$this->upload()]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Original Drive.pdf', LeadDocument::sole()->original_name);
    }

    public function test_staff_requested_and_shared_uploads_also_recover_the_name(): void
    {
        $lead = $this->client();
        $this->asset($lead);
        $request = LeadDocumentRequest::create(['lead_id' => $lead->id, 'label' => 'Requested evidence', 'requested_by' => $this->staff->id, 'requested_at' => now()]);
        $this->post('/admin/leads/'.$lead->id.'/documents/requests/'.$request->id.'/upload', ['files' => [$this->upload()]])->assertRedirect()->assertSessionHasNoErrors();
        $requested = LeadDocument::sole();
        $this->assertSame('Original Drive.pdf', $requested->original_name);
        $this->assertSame($request->id, $requested->request_id);
        $this->assertSame('Submitted', $requested->status);
        $this->post('/admin/leads/'.$lead->id.'/documents/share', ['file' => $this->upload()])->assertRedirect()->assertSessionHasNoErrors();
        $shared = LeadDocument::where('status', 'StaffShared')->sole();
        $this->assertSame('Original Drive.pdf', $shared->original_name);
        $this->assertNotSame($requested->file_path, $shared->file_path);
    }

    public function test_ambiguous_drive_names_keep_the_existing_checklist_naming_fallback(): void
    {
        $lead = $this->client();
        $lead->update(['inz_visa_type' => 'Fallback Visa']);
        VisaType::create(['code' => 'FALLBACK', 'name' => 'Fallback Visa', 'category' => 'Test', 'active' => true, 'filename_pattern' => 'Checklist fallback', 'checklist_items' => [['key' => 'passport', 'label' => 'Passport']]]);
        $this->asset($lead, 'One name.pdf');
        $this->asset($lead, 'Another name.pdf');
        $this->post('/admin/leads/'.$lead->id.'/documents/checklist/passport/upload', ['files' => [$this->upload()]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Checklist fallback.pdf', LeadDocument::sole()->original_name);
    }

    public function test_matching_is_exact_case_scoped_and_requires_one_distinct_name(): void
    {
        $lead = $this->client();
        $other = $this->client();
        $this->asset($other, 'Other client private.pdf');
        $names = app(UploadNames::class);
        $this->assertNull($names->forUpload($lead, $this->upload(), $this->staff));
        $this->asset($lead, 'Original Drive.pdf', true);
        $this->asset($lead, 'Original Drive.pdf');
        $this->assertSame('Original Drive.pdf', $names->forUpload($lead, $this->upload(), $this->staff));
        $this->assertNull($names->forUpload($lead, $this->upload(str_replace('source', 'SOURCE', $this->bytes())), $this->staff));
        $this->asset($lead, 'original drive.pdf');
        $this->assertNull($names->forUpload($lead, $this->upload(), $this->staff));
    }

    public function test_clients_public_uploads_and_unscoped_staff_cannot_discover_private_drive_names(): void
    {
        $lead = $this->client();
        $this->asset($lead, 'Private staff file.pdf');
        $names = app(UploadNames::class);
        $client = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id]);
        $foreignAgent = User::factory()->create(['role' => 'agent']);
        $inactive = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        foreach ([null, $client, $foreignAgent, $inactive] as $actor) {
            $this->assertNull($names->forUpload($lead, $this->upload(), $actor));
        }
        $this->actingAs($client)->post('/portal/lead/documents/checklist/custom_key/upload', ['files' => [$this->upload()]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('renamed-local-copy.pdf', LeadDocument::sole()->original_name);
    }

    public function test_matched_legacy_drive_name_is_sanitized_for_display(): void
    {
        $lead = $this->client();
        $this->asset($lead, "C:\\private\\Canonical\x00 name.pdf");
        $this->assertSame('Canonical name.pdf', app(UploadNames::class)->forUpload($lead, $this->upload(), $this->staff));
    }
}
