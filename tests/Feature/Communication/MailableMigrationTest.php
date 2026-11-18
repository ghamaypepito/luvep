<?php

namespace Tests\Feature\Communication;

use App\Mail\TemplatedMessage;
use App\Mail\TrackerWelcome;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\LeadIntakeService;
use Database\Seeders\DefaultMessageTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailableMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_manual_tracker_welcome_uses_template_when_seeded(): void
    {
        $this->seed(DefaultMessageTemplatesSeeder::class);

        $lead = app(LeadIntakeService::class)->ingest('booking', [
            'first_name' => 'Tess', 'last_name' => 'Lee', 'email' => 'tess@example.com',
        ]);

        // Intake never sends the link, even when an active welcome template exists.
        Mail::assertNothingOutgoing();
        $this->assertSame(0, MessageLog::count());

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post("/admin/leads/{$lead->id}/send-tracker-link")
            ->assertRedirect()->assertSessionHas('success');

        Mail::assertQueued(TemplatedMessage::class, fn ($mail) => $mail->hasTo('tess@example.com'));
        Mail::assertQueuedCount(1);
        Mail::assertNotQueued(TrackerWelcome::class); // template path, not legacy
        $this->assertDatabaseHas('message_logs', [
            'template_key' => 'tracker_welcome', 'channel' => 'email', 'recipient_address' => 'tess@example.com',
        ]);
    }

    public function test_manual_tracker_welcome_falls_back_to_legacy_mailable_when_no_template(): void
    {
        $this->assertNull(MessageTemplate::resolve('tracker_welcome'));
        $lead = app(LeadIntakeService::class)->ingest('booking', [
            'first_name' => 'Old', 'last_name' => 'Way', 'email' => 'old@example.com',
        ]);

        Mail::assertNothingOutgoing();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post("/admin/leads/{$lead->id}/send-tracker-link")
            ->assertRedirect()->assertSessionHas('success');

        Mail::assertQueued(TrackerWelcome::class, fn ($mail) => $mail->hasTo('old@example.com'));
        Mail::assertQueuedCount(1);
        Mail::assertNotQueued(TemplatedMessage::class);
        $this->assertSame(0, MessageLog::count());
    }

    public function test_doc_approved_uses_template_and_substitutes_name(): void
    {
        $this->seed(DefaultMessageTemplatesSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = Lead::create(['first_name' => 'Dee', 'last_name' => 'Lee', 'email' => 'dee@example.com']);
        $doc = LeadDocument::create([
            'lead_id' => $lead->id, 'original_name' => 'passport.pdf', 'file_path' => 'x/passport.pdf',
            'mime' => 'application/pdf', 'size' => 100, 'status' => LeadDocument::STATUS_SUBMITTED, 'source' => 'upload',
        ]);

        $this->actingAs($admin)->post("/admin/leads/{$lead->id}/documents/{$doc->id}/status", [
            'status' => LeadDocument::STATUS_APPROVED,
        ])->assertRedirect();

        Mail::assertQueued(TemplatedMessage::class);
        $log = MessageLog::where('template_key', 'doc_approved')->where('channel', 'email')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('passport.pdf', $log->body);
    }

    public function test_doc_rejected_uses_reupload_template_and_includes_reason(): void
    {
        $this->seed(DefaultMessageTemplatesSeeder::class);
        // Rejection now asks the client to re-upload; the older doc_rejected
        // template is deliberately not the action's template key.
        MessageTemplate::create([
            'key' => 'document_reupload', 'department' => '', 'name' => 'Re-upload document',
            'channels' => ['email'], 'email_subject' => 'Please re-upload {{document_name}}',
            'email_body' => 'Hi {{first_name}}, re-upload {{document_name}}: {{reason}}', 'is_active' => true,
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = Lead::create(['first_name' => 'Rae', 'last_name' => 'Jay', 'email' => 'rae@example.com']);
        $doc = LeadDocument::create([
            'lead_id' => $lead->id, 'original_name' => 'bank.pdf', 'file_path' => 'x/bank.pdf',
            'mime' => 'application/pdf', 'size' => 100, 'status' => LeadDocument::STATUS_SUBMITTED, 'source' => 'upload',
        ]);

        $this->actingAs($admin)->post("/admin/leads/{$lead->id}/documents/{$doc->id}/status", [
            'status' => LeadDocument::STATUS_REJECTED, 'note' => 'Page 2 missing',
        ])->assertRedirect();

        $log = MessageLog::where('template_key', 'document_reupload')->where('channel', 'email')->first();
        $this->assertNotNull($log);
        $this->assertSame('rae@example.com', $log->recipient_address);
        $this->assertStringContainsString('Rae', $log->body);
        $this->assertStringContainsString('bank.pdf', $log->body);
        $this->assertStringContainsString('Page 2 missing', $log->body);
        $this->assertDatabaseMissing('message_logs', ['template_key' => 'doc_rejected']);
        Mail::assertQueued(TemplatedMessage::class, fn ($mail) => $mail->hasTo('rae@example.com'));
        Mail::assertQueuedCount(1);
    }
}
