<?php

namespace Tests\Feature\Track;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Notifications\DocumentSubmittedForReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TrackerDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function lead(array $attrs = []): Lead
    {
        return Lead::create(array_merge(['first_name' => 'Track', 'last_name' => 'Lead'], $attrs));
    }

    private function upload(Lead $lead, ?UploadedFile $file = null, array $extra = [])
    {
        return $this->post("/track/{$lead->tracking_code}/document", array_merge([
            'file' => $file ?? UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
        ], $extra));
    }

    public function test_upload_fires_notification_to_assigned_staff(): void
    {
        Notification::fake();
        $staff = User::factory()->create(['role' => 'immigration']);
        $lead = $this->lead(['assigned_to' => $staff->id]);

        $this->upload($lead)->assertRedirect();

        Notification::assertSentTo($staff, DocumentSubmittedForReview::class, fn ($n) => $n->context === 'submitted');
    }

    public function test_upload_falls_back_to_admins_when_unassigned(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = $this->lead(); // no assigned_to

        $this->upload($lead)->assertRedirect();

        Notification::assertSentTo($admin, DocumentSubmittedForReview::class);
    }

    public function test_upload_respects_mime_validation(): void
    {
        $lead = $this->lead();

        $this->upload($lead, UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload'))
            ->assertSessionHasErrors('files.0');

        $this->assertSame(0, $lead->documents()->count());
    }

    public function test_replace_deletes_old_file_and_notifies(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = $this->lead();

        $this->upload($lead);
        $doc = $lead->documents()->first();
        $oldPath = $doc->file_path;
        Storage::disk('local')->assertExists($oldPath);
        Storage::disk('public')->assertMissing($oldPath);

        $this->post("/track/{$lead->tracking_code}/document/{$doc->id}", [
            'file' => UploadedFile::fake()->create('passport-v2.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $doc->refresh();
        $this->assertNotSame($oldPath, $doc->file_path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($doc->file_path);
        Storage::disk('public')->assertMissing($doc->file_path);
        Notification::assertSentTo($admin, DocumentSubmittedForReview::class, fn ($n) => $n->context === 'replaced');
    }

    public function test_lead_cannot_delete_approved_document(): void
    {
        $lead = $this->lead();
        $this->upload($lead);
        $doc = $lead->documents()->first();
        $doc->update(['status' => LeadDocument::STATUS_APPROVED]);

        $this->delete("/track/{$lead->tracking_code}/document/{$doc->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('lead_documents', ['id' => $doc->id]); // still there
    }

    public function test_lead_can_delete_rejected_document(): void
    {
        $lead = $this->lead();
        $this->upload($lead);
        $doc = $lead->documents()->first();
        $doc->update(['status' => LeadDocument::STATUS_REJECTED]);

        $this->delete("/track/{$lead->tracking_code}/document/{$doc->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('lead_documents', ['id' => $doc->id]);
    }

    public function test_document_status_change_is_audit_logged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = $this->lead();
        $this->upload($lead);
        $doc = $lead->documents()->first();

        $this->actingAs($admin)->post("/admin/leads/{$lead->id}/documents/{$doc->id}/status", [
            'status' => LeadDocument::STATUS_APPROVED,
        ])->assertRedirect();

        $this->assertTrue(
            ActivityLog::where('action', 'lead_document.updated')->exists()
        );
    }

    public function test_cross_lead_access_is_blocked(): void
    {
        $leadA = $this->lead();
        $leadB = $this->lead();
        $this->upload($leadA);
        $docA = $leadA->documents()->first();

        // Lead B's code cannot delete Lead A's document.
        $this->delete("/track/{$leadB->tracking_code}/document/{$docA->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('lead_documents', ['id' => $docA->id]);
    }
}
