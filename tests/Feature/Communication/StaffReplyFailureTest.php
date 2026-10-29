<?php

namespace Tests\Feature\Communication;

use App\Models\EmailReply;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffReplyFailureTest extends TestCase
{
    use RefreshDatabase;

    public static function jsonComposers(): array
    {
        return ['conversation' => ['communications'], 'message panel' => ['compose']];
    }

    #[DataProvider('jsonComposers')]
    public function test_failed_email_queue_is_reported_to_the_composer(string $endpoint): void
    {
        $user = User::factory()->create(['role' => 'sales']);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Recipient', 'email' => 'recipient@example.test']);
        // Fail the external boundary while exercising the real send service.
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Synthetic queue outage'));
        $this->actingAs($user)->postJson("/admin/leads/{$lead->id}/$endpoint", ['channel' => 'email', 'subject' => 'Follow-up', 'body' => 'Please review'])
            ->assertStatus(502)->assertJsonStructure(['message']);
        $this->assertDatabaseHas('message_logs', ['recipient_id' => $lead->id, 'status' => 'failed']);
    }

    public function test_failed_inbox_reply_does_not_mark_client_answered_or_add_an_outbound_reply(): void
    {
        $user = User::factory()->create(['role' => 'education']);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Recipient', 'email' => 'recipient@example.test']);
        $inbound = EmailReply::create(['lead_id' => $lead->id, 'direction' => 'inbound', 'from_email' => $lead->email, 'to_email' => 'team@example.test', 'subject' => 'Question', 'body_text' => 'Please help', 'received_at' => now(), 'is_read' => false]);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Synthetic queue outage'));
        $this->actingAs($user)->post("/portal/education/email/replies/{$lead->id}/reply", ['body' => 'Our response'])
            ->assertRedirect()->assertSessionHasErrors('body')->assertSessionMissing('success');
        $this->assertFalse($inbound->fresh()->is_read);
        $this->assertDatabaseCount('email_replies', 1);
        $this->assertDatabaseHas('message_logs', ['recipient_id' => $lead->id, 'status' => 'failed']);
    }
}
