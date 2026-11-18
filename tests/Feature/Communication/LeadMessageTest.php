<?php

namespace Tests\Feature\Communication;

use App\Mail\TemplatedMessage;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeadMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function lead(array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'first_name' => 'Mara', 'last_name' => 'Cruz', 'email' => 'mara@example.com',
        ], $attrs));
    }

    private function tmpl(array $attrs = []): MessageTemplate
    {
        return MessageTemplate::create(array_merge([
            'key' => 'application_status_update', 'department' => 'sales', 'name' => 'Status',
            'channels' => ['email'], 'email_subject' => 'Update for {{first_name}}',
            'email_body' => 'Your status is now: {{status}}. {{status_detail}}', 'is_active' => true,
        ], $attrs));
    }

    public function test_staff_sees_only_own_and_shared_email_templates(): void
    {
        // Migrations install shared templates too; preserve them in the expected pool.
        $existingCount = MessageTemplate::active()->whereIn('department', ['sales', ''])->get()
            ->filter(fn ($t) => $t->hasChannel('email'))->count();
        $this->tmpl(['department' => 'sales', 'key' => 'sales_email']);
        $this->tmpl(['department' => '', 'key' => 'shared_email']);
        $this->tmpl(['department' => 'education', 'key' => 'edu_email']);          // other dept — hidden
        $this->tmpl(['department' => 'sales', 'key' => 'sales_sms', 'channels' => ['sms']]); // not email — hidden
        $this->tmpl(['key' => 'inactive_email', 'is_active' => false]);

        $response = $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->getJson('/lead-message/templates')
            ->assertOk()
            ->assertJsonCount($existingCount + 2);

        $keys = collect($response->json())->pluck('key')->all();
        $this->assertContains('sales_email', $keys);
        $this->assertContains('shared_email', $keys);
        $this->assertNotContains('edu_email', $keys);
        $this->assertNotContains('sales_sms', $keys);
        $this->assertNotContains('inactive_email', $keys);
    }

    public function test_send_emails_lead_logs_and_substitutes_status(): void
    {
        $lead = $this->lead();
        $template = $this->tmpl(['department' => 'sales']);

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->post("/leads/{$lead->id}/send-message", [
                'template_id' => $template->id,
                'status' => 'Approved',
                'status_detail' => 'Congratulations!',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertQueued(TemplatedMessage::class, fn ($m) => $m->hasTo('mara@example.com'));

        $log = MessageLog::where('template_key', 'application_status_update')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Approved', $log->body);
        $this->assertStringContainsString('Congratulations!', $log->body);
    }

    public function test_staff_cannot_send_another_departments_template(): void
    {
        $lead = $this->lead();
        $template = $this->tmpl(['department' => 'education']);

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->post("/leads/{$lead->id}/send-message", ['template_id' => $template->id])
            ->assertForbidden();

        Mail::assertNothingQueued();
    }

    public function test_admin_can_send_any_template(): void
    {
        $lead = $this->lead();
        $template = $this->tmpl(['department' => 'education']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post("/leads/{$lead->id}/send-message", ['template_id' => $template->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertQueued(TemplatedMessage::class);
    }

    public function test_send_with_attachment_stores_and_attaches(): void
    {
        Storage::fake('local');
        $lead = $this->lead();
        $template = $this->tmpl(['department' => 'sales']);

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->post("/leads/{$lead->id}/send-message", [
                'template_id' => $template->id,
                'status' => 'Approved',
                'attachments' => [UploadedFile::fake()->create('offer.pdf', 120, 'application/pdf')],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertQueued(TemplatedMessage::class, fn ($m) => count($m->attachmentFiles) === 1
            && $m->attachmentFiles[0]['name'] === 'offer.pdf');

        $this->assertCount(1, Storage::disk('local')->files("email-attachments/{$lead->id}"));
    }

    public function test_attachment_rejects_disallowed_type(): void
    {
        $lead = $this->lead();
        $template = $this->tmpl(['department' => 'sales']);

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->post("/leads/{$lead->id}/send-message", [
                'template_id' => $template->id,
                'attachments' => [UploadedFile::fake()->create('malware.exe', 10)],
            ])
            ->assertSessionHasErrors('attachments.0');

        Mail::assertNothingQueued();
    }

    public function test_lead_without_email_is_rejected(): void
    {
        $lead = $this->lead(['email' => null]);
        $template = $this->tmpl(['department' => 'sales']);

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->post("/leads/{$lead->id}/send-message", ['template_id' => $template->id])
            ->assertSessionHas('error');

        Mail::assertNothingQueued();
    }
}
