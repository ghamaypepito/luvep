<?php

namespace Tests\Feature\Communication;

use App\Jobs\SendCampaign;
use App\Mail\TemplatedMessage;
use App\Models\EmailCampaign;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\CommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BulkEmailTest extends TestCase
{
    use RefreshDatabase;

    private function sales(): User
    {
        return User::factory()->create(['role' => 'sales']);
    }

    private function lead(array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'first_name' => 'Mara', 'last_name' => 'Cruz', 'email' => 'mara@example.com', 'stage' => 'Qualified',
        ], $attrs));
    }

    private function tmpl(array $attrs = []): MessageTemplate
    {
        return MessageTemplate::create(array_merge([
            'key' => 'promo', 'department' => 'sales', 'name' => 'Promo', 'channels' => ['email'],
            'email_subject' => 'Hi {{first_name}}', 'email_body' => 'Hello {{first_name}}, stage {{stage}}', 'is_active' => true,
        ], $attrs));
    }

    public function test_index_lists_active_email_templates_across_departments_and_emailable_recipients(): void
    {
        $existingCount = MessageTemplate::active()->get()->filter(fn ($t) => $t->hasChannel('email'))->count();
        $this->tmpl(['department' => 'sales']);
        $this->tmpl(['department' => 'education', 'key' => 'edu', 'name' => 'Education']);
        $this->tmpl(['key' => 'inactive', 'name' => 'Inactive', 'is_active' => false]);
        $this->tmpl(['key' => 'sms_only', 'name' => 'SMS only', 'channels' => ['sms']]);
        $this->lead();
        $this->lead(['email' => null]);

        $this->actingAs($this->sales())
            ->get('/portal/sales/bulk-email')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('portal/sales/BulkEmail')
                ->has('templates', $existingCount + 2)
                ->where('templates', fn ($templates) => collect($templates)->pluck('name')->contains('Promo')
                    && collect($templates)->pluck('name')->contains('Education')
                    && ! collect($templates)->pluck('name')->contains('Inactive')
                    && ! collect($templates)->pluck('name')->contains('SMS only'))
                ->has('recipients', 1));
    }

    public function test_send_now_creates_campaign_and_dispatches_job(): void
    {
        Queue::fake();
        $lead = $this->lead();
        $tmpl = $this->tmpl();

        $this->actingAs($this->sales())
            ->post('/portal/sales/bulk-email', [
                'name' => 'June blast', 'template_id' => $tmpl->id,
                'recipient_lead_ids' => [$lead->id], 'action' => 'send_now',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('email_campaigns', ['name' => 'June blast', 'status' => 'sending', 'total_recipients' => 1]);
        Queue::assertPushed(SendCampaign::class);
    }

    public function test_schedule_stores_future_campaign_without_dispatching(): void
    {
        Queue::fake();
        $lead = $this->lead();
        $tmpl = $this->tmpl();

        $this->actingAs($this->sales())
            ->post('/portal/sales/bulk-email', [
                'name' => 'Later', 'template_id' => $tmpl->id, 'recipient_lead_ids' => [$lead->id],
                'action' => 'schedule', 'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('email_campaigns', ['name' => 'Later', 'status' => 'scheduled']);
        Queue::assertNotPushed(SendCampaign::class);
    }

    public function test_only_emailable_recipients_are_snapshotted(): void
    {
        Queue::fake();
        $with = $this->lead(['email' => 'has@example.com']);
        $without = $this->lead(['email' => null]);
        $tmpl = $this->tmpl();

        $this->actingAs($this->sales())->post('/portal/sales/bulk-email', [
            'name' => 'Mix', 'template_id' => $tmpl->id,
            'recipient_lead_ids' => [$with->id, $without->id], 'action' => 'send_now',
        ])->assertRedirect();

        $campaign = EmailCampaign::first();
        $this->assertSame(1, $campaign->total_recipients);
        $this->assertSame([$with->id], $campaign->recipient_lead_ids);
    }

    public function test_can_use_another_departments_template_without_changing_campaign_ownership(): void
    {
        Queue::fake();
        $lead = $this->lead();
        $edu = $this->tmpl(['department' => 'education', 'key' => 'edu']);

        $this->actingAs($this->sales())->post('/portal/sales/bulk-email', [
            'name' => 'X', 'template_id' => $edu->id, 'recipient_lead_ids' => [$lead->id], 'action' => 'send_now',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('email_campaigns', [
            'name' => 'X', 'department' => 'sales', 'template_id' => $edu->id,
            'status' => 'sending', 'total_recipients' => 1,
        ]);
        Queue::assertPushed(SendCampaign::class, 1);
    }

    public function test_scheduled_campaign_can_be_canceled(): void
    {
        $campaign = EmailCampaign::create([
            'name' => 'Sched', 'department' => 'sales', 'subject' => 'S', 'body' => 'B',
            'status' => 'scheduled', 'scheduled_at' => now()->addDay(), 'total_recipients' => 1, 'recipient_lead_ids' => [1],
        ]);

        $this->actingAs($this->sales())
            ->post("/portal/sales/bulk-email/{$campaign->id}/cancel")
            ->assertRedirect();

        $this->assertSame('canceled', $campaign->fresh()->status);
    }

    public function test_dispatch_due_command_fires_due_campaigns(): void
    {
        Queue::fake();
        EmailCampaign::create([
            'name' => 'Due', 'department' => 'sales', 'subject' => 'S', 'body' => 'B',
            'status' => 'scheduled', 'scheduled_at' => now()->subMinute(), 'total_recipients' => 1, 'recipient_lead_ids' => [1],
        ]);
        EmailCampaign::create([
            'name' => 'Future', 'department' => 'sales', 'subject' => 'S', 'body' => 'B',
            'status' => 'scheduled', 'scheduled_at' => now()->addHour(), 'total_recipients' => 1, 'recipient_lead_ids' => [1],
        ]);

        $this->artisan('campaigns:dispatch-due')->assertSuccessful();

        Queue::assertPushed(SendCampaign::class, 1);
        $this->assertSame('sending', EmailCampaign::where('name', 'Due')->first()->status);
        $this->assertSame('scheduled', EmailCampaign::where('name', 'Future')->first()->status);
    }

    public function test_job_sends_personalised_emails_and_tallies(): void
    {
        Mail::fake();
        $a = $this->lead(['email' => 'a@example.com', 'first_name' => 'Ann']);
        $b = $this->lead(['email' => 'b@example.com', 'first_name' => 'Bob']);

        $campaign = EmailCampaign::create([
            'name' => 'Run', 'department' => 'sales', 'subject' => 'Hi {{first_name}}',
            'body' => 'Hello {{first_name}}', 'status' => 'sending',
            'total_recipients' => 2, 'recipient_lead_ids' => [$a->id, $b->id],
        ]);

        (new SendCampaign($campaign->id))->handle(app(CommunicationService::class));

        Mail::assertQueued(TemplatedMessage::class, 2);
        $campaign->refresh();
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(2, $campaign->sent_count);
        $this->assertDatabaseHas('message_logs', ['campaign_id' => $campaign->id, 'recipient_address' => 'a@example.com']);
    }
}
