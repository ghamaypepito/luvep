<?php

namespace Tests\Feature\Sales;

use App\Contracts\SmsProvider;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ComposeMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function fakeSmsOk(): void
    {
        $this->app->instance(SmsProvider::class, new class implements SmsProvider {
            public function isConfigured(): bool { return true; }
            public function send(string $to, string $body): array
            {
                return ['ok' => true, 'message_id' => 'SM_1', 'status' => 'queued', 'error' => null];
            }
        });
    }

    private function lead(array $a = []): Lead
    {
        return Lead::create(array_merge([
            'first_name' => 'Cy', 'last_name' => 'Lead', 'email' => 'cy@example.com', 'phone' => '021 555 0100',
        ], $a));
    }

    private function template(array $attributes = []): MessageTemplate
    {
        return MessageTemplate::create(array_merge([
            'key' => 'compose_update', 'department' => 'sales', 'name' => 'Compose update',
            'channels' => ['email', 'sms'], 'email_subject' => 'Update for {{first_name}}',
            'email_body' => 'Email for {{first_name}}', 'sms_body' => 'SMS for {{first_name}}',
            'is_active' => true,
        ], $attributes));
    }

    public function test_compose_picker_lists_only_active_own_and_shared_templates(): void
    {
        $lead = $this->lead();
        $own = $this->template(['key' => 'own_picker', 'channels' => ['email']]);
        $shared = $this->template(['key' => 'shared_picker', 'department' => '', 'channels' => ['sms']]);
        $other = $this->template(['key' => 'other_picker', 'department' => 'education']);
        $inactive = $this->template(['key' => 'inactive_picker', 'is_active' => false]);
        $deleted = $this->template(['key' => 'deleted_picker']);
        $deleted->delete();

        $response = $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->getJson("/admin/leads/{$lead->id}/compose/templates")->assertOk();

        $templates = collect($response->json());
        $this->assertSame(['id' => $own->id, 'name' => 'Compose update', 'channels' => ['email']], $templates->firstWhere('id', $own->id));
        $this->assertSame(['id' => $shared->id, 'name' => 'Compose update', 'channels' => ['sms']], $templates->firstWhere('id', $shared->id));
        $this->assertNotContains($other->id, $templates->pluck('id'));
        $this->assertNotContains($inactive->id, $templates->pluck('id'));
        $this->assertNotContains($deleted->id, $templates->pluck('id'));
    }

    public function test_admin_compose_picker_includes_other_departments(): void
    {
        $lead = $this->lead();
        $template = $this->template(['department' => 'education']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson("/admin/leads/{$lead->id}/compose/templates")
            ->assertOk()->assertJsonFragment(['id' => $template->id]);
    }

    public function test_compose_picker_checks_access_to_the_actual_lead(): void
    {
        $lead = $this->lead(['is_immigration_case' => true]);

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->getJson("/admin/leads/{$lead->id}/compose/templates")->assertForbidden();
    }

    public function test_external_account_cannot_load_staff_compose_templates(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $lead = $this->lead(['agent_id' => $agent->id]);

        $this->actingAs($agent)->getJson("/admin/leads/{$lead->id}/compose/templates")->assertForbidden();
    }

    public static function missingTemplateRecipients(): array
    {
        return [
            'email missing' => ['email', ['email' => null], ['email']],
            'phone missing' => ['sms', ['phone' => null], ['phone']],
            'both missing' => ['both', ['email' => null, 'phone' => null], ['email', 'phone']],
            'both requested with email missing' => ['both', ['email' => null], ['email']],
            'both requested with phone missing' => ['both', ['phone' => null], ['phone']],
        ];
    }

    #[DataProvider('missingTemplateRecipients')]
    public function test_template_send_rejects_missing_recipients_before_sending_any_channel(string $channel, array $attributes, array $errors): void
    {
        $this->fakeSmsOk();
        $lead = $this->lead($attributes);
        $template = $this->template();

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->postJson("/admin/leads/{$lead->id}/compose", ['channel' => $channel, 'template_id' => $template->id])
            ->assertUnprocessable()->assertJsonValidationErrors($errors);

        $this->assertSame(0, MessageLog::count());
        Mail::assertNothingQueued();
    }

    public function test_selected_template_supplies_content_without_custom_subject_or_body(): void
    {
        $lead = $this->lead();
        $template = $this->template();
        $this->template(['department' => '', 'email_body' => 'Wrong shared template']);

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->postJson("/admin/leads/{$lead->id}/compose", ['channel' => 'email', 'template_id' => $template->id])
            ->assertOk()->assertJsonPath('count', 1);

        $this->assertDatabaseHas('message_logs', [
            'recipient_id' => $lead->id, 'channel' => 'email', 'template_key' => 'compose_update',
            'subject' => 'Update for Cy', 'body' => 'Email for Cy', 'status' => MessageLog::STATUS_QUEUED,
        ]);
        $this->assertSame(0, MessageLog::where('channel', 'sms')->count());
        $this->assertSame(['email', 'sms'], $template->fresh()->channels);
    }

    public function test_selected_template_sends_only_the_requested_sms_channel(): void
    {
        $this->fakeSmsOk();
        $lead = $this->lead();
        $template = $this->template(['department' => '']);

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->postJson("/admin/leads/{$lead->id}/compose", [
                'channel' => 'sms', 'template_id' => $template->id, 'body' => 'Ignored custom body',
            ])->assertOk()->assertJsonPath('count', 1);

        $this->assertDatabaseHas('message_logs', ['channel' => 'sms', 'body' => 'SMS for Cy']);
        Mail::assertNothingQueued();
    }

    public function test_selected_template_can_send_both_channels(): void
    {
        $this->fakeSmsOk();
        $lead = $this->lead();
        $template = $this->template();

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->postJson("/admin/leads/{$lead->id}/compose", ['channel' => 'both', 'template_id' => $template->id])
            ->assertOk()->assertJsonPath('count', 2);

        $this->assertDatabaseHas('message_logs', ['channel' => 'email', 'body' => 'Email for Cy']);
        $this->assertDatabaseHas('message_logs', ['channel' => 'sms', 'body' => 'SMS for Cy']);
    }

    public function test_staff_cannot_compose_using_another_departments_template(): void
    {
        $lead = $this->lead();
        $template = $this->template(['department' => 'education']);

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->postJson("/admin/leads/{$lead->id}/compose", [
                'channel' => 'email', 'template_id' => $template->id, 'subject' => 'S', 'body' => 'B',
            ])->assertForbidden();

        $this->assertSame(0, MessageLog::count());
        Mail::assertNothingQueued();
    }

    public function test_admin_can_compose_using_another_departments_selected_template(): void
    {
        $lead = $this->lead();
        $template = $this->template(['department' => 'education']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson("/admin/leads/{$lead->id}/compose", ['channel' => 'email', 'template_id' => $template->id])
            ->assertOk()->assertJsonPath('count', 1);

        $this->assertDatabaseHas('message_logs', ['channel' => 'email', 'body' => 'Email for Cy']);
    }

    public function test_inactive_selected_template_cannot_fall_back_to_active_shared_template(): void
    {
        $lead = $this->lead();
        $template = $this->template(['is_active' => false]);
        $this->template(['department' => '']);

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->postJson("/admin/leads/{$lead->id}/compose", [
                'channel' => 'email', 'template_id' => $template->id, 'subject' => 'S', 'body' => 'B',
            ])->assertUnprocessable()->assertJsonValidationErrors('template_id');

        $this->assertSame(0, MessageLog::count());
        Mail::assertNothingQueued();
    }

    public function test_template_must_support_all_requested_channels(): void
    {
        $lead = $this->lead();
        $template = $this->template(['department' => '', 'channels' => ['email']]);

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->postJson("/admin/leads/{$lead->id}/compose", [
                'channel' => 'both', 'template_id' => $template->id, 'subject' => 'S', 'body' => 'B',
            ])->assertUnprocessable()->assertJsonValidationErrors('channel');

        $this->assertSame(0, MessageLog::count());
        Mail::assertNothingQueued();
    }

    public function test_custom_email_still_requires_subject_and_body(): void
    {
        $lead = $this->lead();

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->postJson("/admin/leads/{$lead->id}/compose", ['channel' => 'email'])
            ->assertUnprocessable()->assertJsonValidationErrors(['subject', 'body']);

        $this->assertSame(0, MessageLog::count());
        Mail::assertNothingQueued();
    }

    public function test_staff_can_compose_to_an_accessible_lead(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $lead = $this->lead();

        $this->actingAs($sales)
            ->postJson("/admin/leads/{$lead->id}/compose", ['channel' => 'email', 'subject' => 'Hi', 'body' => 'Hello {{first_name}}'])
            ->assertOk()->assertJsonPath('count', 1);
    }

    public function test_staff_cannot_compose_to_a_lead_outside_their_department(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $case = $this->lead(['is_immigration_case' => true]);

        $this->actingAs($sales)
            ->postJson("/admin/leads/{$case->id}/compose", ['channel' => 'email', 'subject' => 'Hi', 'body' => 'x'])
            ->assertForbidden();
    }

    public function test_email_only_creates_one_email_log(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $lead = $this->lead();

        $this->actingAs($sales)->postJson("/admin/leads/{$lead->id}/compose", ['channel' => 'email', 'subject' => 'S', 'body' => 'B']);

        $this->assertSame(1, MessageLog::where('channel', 'email')->count());
        $this->assertSame(0, MessageLog::where('channel', 'sms')->count());
    }

    public function test_sms_only_creates_one_sms_log(): void
    {
        $this->fakeSmsOk();
        $sales = User::factory()->create(['role' => 'sales']);
        $lead = $this->lead();

        $this->actingAs($sales)->postJson("/admin/leads/{$lead->id}/compose", ['channel' => 'sms', 'body' => 'Txt'])->assertOk();

        $this->assertSame(0, MessageLog::where('channel', 'email')->count());
        $this->assertSame(1, MessageLog::where('channel', 'sms')->count());
    }

    public function test_both_creates_two_log_entries(): void
    {
        $this->fakeSmsOk();
        $sales = User::factory()->create(['role' => 'sales']);
        $lead = $this->lead();

        $this->actingAs($sales)
            ->postJson("/admin/leads/{$lead->id}/compose", ['channel' => 'both', 'subject' => 'S', 'body' => 'B'])
            ->assertOk()->assertJsonPath('count', 2);

        $this->assertSame(2, MessageLog::count());
    }
}
