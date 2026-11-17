<?php

namespace Tests\Feature\Education;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadNote;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OverviewAttachmentDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        Bus::fake();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => 'education']));
    }

    private function student(): Lead
    {
        return Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Student', 'is_student' => true, 'education_stage' => 'Proposal Sent']);
    }

    private function upload(Lead $lead, ?int $parentId = null): LeadNote
    {
        $this->post("/admin/leads/{$lead->id}/notes", [
            'body' => 'Private internal context', 'parent_id' => $parentId,
            'files' => [UploadedFile::fake()->createWithContent('synthetic.pdf', "%PDF-1.4\nSynthetic file")],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return $lead->notes()->latest('id')->firstOrFail();
    }

    public function test_profile_supplies_existing_and_reply_attachments_without_copying_or_changing_checklist_documents(): void
    {
        $lead = $this->student();
        $existing = LeadDocument::create(['lead_id' => $lead->id, 'checklist_key' => 'acad.transcript', 'original_name' => 'approved.pdf', 'file_path' => 'approved.pdf', 'status' => 'Approved', 'source' => 'upload']);
        $before = $existing->getRawOriginal();
        $note = $this->upload($lead);
        $reply = $this->upload($lead, $note->id);
        $other = $this->upload($this->student());
        $metadata = $note->attachments;
        $this->get("/portal/education/leads/{$lead->id}?tab=documents&nav=students")
            ->assertOk()->assertInertia(fn ($page) => $page
            ->component('portal/education/LeadDetails')->where('portalBase', '/portal/education')
            ->has('notes', 2)->where('notes', function ($notes) use ($note, $reply, $other) {
                $rows = collect($notes)->keyBy('id');
                $this->assertEqualsCanonicalizing([$note->id, $reply->id], $rows->keys()->all());
                $this->assertFalse($rows->has($other->id));
                foreach ([$note, $reply] as $entry) {
                    $file = $rows[$entry->id]['attachments'][0];
                    $this->assertSame('synthetic.pdf', $file['name']);
                    $this->assertSame(url("/admin/leads/{$entry->lead_id}/notes/{$entry->id}/attachments/0"), $file['url']);
                    $this->assertArrayNotHasKey('path', $file);
                    $this->assertArrayNotHasKey('media_file_id', $file);
                }

                return true;
            }));
        $this->assertSame($metadata, $note->fresh()->attachments);
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        $this->assertDatabaseCount('lead_documents', 1);
        $this->assertCount(3, Storage::disk('local')->allFiles());
    }

    public function test_private_links_keep_download_inline_record_and_account_boundaries(): void
    {
        $lead = $this->student();
        $note = $this->upload($lead);
        $url = "/admin/leads/{$lead->id}/notes/{$note->id}/attachments/0";
        $this->get($url)->assertDownload('synthetic.pdf');
        $this->get($url.'?inline=1')->assertOk()->assertHeader('content-type', 'application/pdf');
        $other = $this->student();
        $this->get("/admin/leads/{$other->id}/notes/{$note->id}/attachments/0")->assertNotFound();
        foreach (['finance', 'lead', 'agent'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'lead_id' => $lead->id]))->get($url)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'education']));
        Storage::disk('local')->delete($note->attachments[0]['path']);
        $this->get($url)->assertNotFound();
    }

    public function test_deleted_notes_disappear_and_internal_files_are_not_promoted_to_client_documents(): void
    {
        $lead = $this->student();
        $note = $this->upload($lead);
        $this->actingAs(User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id]))
            ->get('/portal/lead/documents')->assertOk()
            ->assertInertia(fn ($page) => $page->missing('notes')->missing('noteDocuments'));
        $this->assertDatabaseCount('lead_documents', 0);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->delete("/admin/leads/{$lead->id}/notes/{$note->id}")->assertRedirect();
        $this->get("/portal/education/leads/{$lead->id}?tab=documents")
            ->assertInertia(fn ($page) => $page->has('notes', 0));
        $this->get("/admin/leads/{$lead->id}/notes/{$note->id}/attachments/0")->assertNotFound();
    }

    public function test_media_drive_references_use_existing_private_stream_without_creating_document_copies(): void
    {
        $lead = $this->student();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $folder = $this->postJson('/media-drive/api/folders', ['client_type' => 'lead', 'client_id' => $lead->id, 'name' => 'Student files'])->assertCreated()->json('folder.id');
        $id = $this->postJson("/media-drive/api/folders/{$folder}/files", ['upload_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('drive.pdf', 5, 'application/pdf')])->assertCreated()->json('file.id');
        $file = MediaFile::findOrFail($id);
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $this->post("/admin/leads/{$lead->id}/notes", ['body' => 'From Media Drive', 'media_file_ids' => [$id]])->assertRedirect()->assertSessionHasNoErrors();
        $note = $lead->notes()->sole();
        $url = "/admin/leads/{$lead->id}/notes/{$note->id}/attachments/0";
        $this->get($url)->assertDownload('drive.pdf');
        $this->assertSame($id, $note->attachments[0]['media_file_id']);
        $this->assertDatabaseCount('lead_documents', 0);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        Storage::disk('local')->assertExists($file->file_path);
        $this->actingAs(User::factory()->create(['role' => 'finance']))->get($url)->assertForbidden();
    }
}
