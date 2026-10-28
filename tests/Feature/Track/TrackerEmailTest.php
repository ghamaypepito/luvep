<?php

namespace Tests\Feature\Track;

use App\Mail\DocumentRequestedFromLead;
use App\Mail\DocumentStatusChanged;
use App\Mail\TrackerWelcome;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Services\LeadIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TrackerEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_lead_creation_with_email_does_not_automatically_send_tracker_welcome(): void
    {
        $lead = app(LeadIntakeService::class)->ingest('booking', [
            'first_name' => 'New', 'last_name' => 'Lead', 'email' => 'new@example.com',
        ]);

        $this->assertNotNull($lead->id);
        $this->assertNotEmpty($lead->tracking_code);
        Mail::assertNothingOutgoing();
    }

    public function test_lead_creation_without_email_does_not_queue_and_does_not_crash(): void
    {
        $lead = app(LeadIntakeService::class)->ingest('booking', [
            'first_name' => 'No', 'last_name' => 'Email',
        ]);

        $this->assertNotNull($lead->id);
        Mail::assertNothingQueued();
    }

    public function test_manual_send_tracker_link_from_lead_detail(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = Lead::create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.com']);

        $this->actingAs($admin)
            ->post("/admin/leads/{$lead->id}/send-tracker-link")
            ->assertRedirect();

        Mail::assertQueued(TrackerWelcome::class, fn ($m) => $m->hasTo('pat@example.com'));
    }

    public function test_send_tracker_link_with_no_email_is_a_friendly_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = Lead::create(['first_name' => 'No', 'last_name' => 'Email']);

        $this->actingAs($admin)
            ->post("/admin/leads/{$lead->id}/send-tracker-link")
            ->assertRedirect();

        Mail::assertNothingQueued();
    }

    public function test_document_request_emails_the_lead(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = Lead::create(['first_name' => 'Doc', 'last_name' => 'Lead', 'email' => 'doc@example.com']);

        $this->actingAs($admin)->post("/admin/leads/{$lead->id}/documents/requests", [
            'items' => [['label' => 'Passport bio page', 'required' => true]],
        ])->assertRedirect();

        Mail::assertQueued(DocumentRequestedFromLead::class, fn ($m) => $m->hasTo('doc@example.com'));
    }

    public function test_document_status_change_emails_the_lead(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = Lead::create(['first_name' => 'St', 'last_name' => 'Lead', 'email' => 'st@example.com']);
        $doc = LeadDocument::create([
            'lead_id' => $lead->id, 'original_name' => 'x.pdf', 'file_path' => 'lead-documents/x.pdf',
            'mime' => 'application/pdf', 'size' => 100, 'status' => LeadDocument::STATUS_SUBMITTED, 'source' => 'upload',
        ]);

        $this->actingAs($admin)->post("/admin/leads/{$lead->id}/documents/{$doc->id}/status", [
            'status' => LeadDocument::STATUS_REJECTED, 'note' => 'Blurry scan',
        ])->assertRedirect();

        Mail::assertQueued(DocumentStatusChanged::class, fn ($m) => $m->hasTo('st@example.com'));
    }

    public function test_mailables_render_without_errors_and_use_app_url(): void
    {
        config(['app.url' => 'https://track.example.test']);
        $lead = Lead::create(['first_name' => 'R', 'last_name' => 'L', 'email' => 'r@example.com']);

        $welcome = new TrackerWelcome($lead);
        $rendered = $welcome->render();

        $this->assertStringContainsString("https://track.example.test/track/{$lead->tracking_code}", $rendered);
    }
}
