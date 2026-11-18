<?php

namespace Tests\Feature\Immigration;

use App\Mail\TemplatedMessage;
use App\Models\EmailAutomationMessage;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DeclineTemplateAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_decline_sends_configured_client_template_once_instead_of_raw_notice(): void
    {
        Mail::fake();
        $case = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Case', 'email' => 'case@example.test', 'is_immigration_case' => true]);
        MessageTemplate::create(['key' => 'visa_decline', 'department' => 'immigration', 'name' => 'Decline', 'channels' => ['email'], 'email_subject' => 'Configured decline', 'email_body' => 'Dear {{first_name}}, configured instructions.', 'is_active' => true]);
        EmailAutomationMessage::create(['event_key' => 'immigration.stage.decline_visa', 'recipient' => 'client', 'template_key' => 'visa_decline', 'channel' => 'email', 'enabled' => true]);
        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->post("/portal/immigration/cases/{$case->id}/decline", ['notify' => true])->assertSessionHasNoErrors();
        Mail::assertQueued(TemplatedMessage::class, 1);
        Mail::assertQueued(TemplatedMessage::class, fn ($mail) => $mail->subjectLine === 'Configured decline' && $mail->hasTo('case@example.test'));
        $this->assertDatabaseHas('message_logs', ['recipient_id' => $case->id, 'template_key' => 'visa_decline']);
    }

    public function test_unchecked_notification_never_sends_or_queues_client_mail(): void
    {
        Mail::fake();
        $case = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Case', 'email' => 'case@example.test', 'is_immigration_case' => true]);
        $this->actingAs(User::factory()->create(['role' => 'immigration']))
            ->post("/portal/immigration/cases/{$case->id}/decline", ['notify' => false])->assertSessionHasNoErrors();
        Mail::assertNothingOutgoing();
    }
    public function test_external_users_cannot_decline_or_email_an_unowned_case_with_portal_grants(): void
    {
        Mail::fake();
        $case = Lead::create(['first_name' => 'Private', 'last_name' => 'Case', 'email' => 'private@example.test', 'is_immigration_case' => true]);
        foreach (['lead', 'agent', 'sub_agent'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['immigration']]))
                ->post("/portal/immigration/cases/{$case->id}/decline", ['notify' => true])->assertForbidden();
        }
        Mail::assertNothingOutgoing();
        $this->assertNull($case->fresh()->immigration_stage);
    }

}
