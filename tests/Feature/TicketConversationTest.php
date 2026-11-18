<?php

namespace Tests\Feature;

use App\Models\SystemTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketConversationTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(User $user): SystemTicket
    {
        return SystemTicket::create(['title' => 'Private issue', 'description' => 'Original details', 'category' => 'bug', 'priority' => 'normal', 'status' => 'open', 'submitted_by' => $user->id, 'department' => 'sales']);
    }

    public function test_only_sender_and_active_support_can_read_and_reply(): void
    {
        $sender = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $ticket = $this->ticket($sender);
        $other = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($other)->getJson("/system-tickets/{$ticket->id}/conversation")->assertForbidden();
        $this->postJson("/system-tickets/{$ticket->id}/messages", ['body' => 'Intrusion'])->assertForbidden();
        $this->actingAs($sender)->postJson("/system-tickets/{$ticket->id}/messages", ['body' => 'More details', 'is_support' => true])->assertCreated()->assertJsonPath('message.is_support', false);
        $this->getJson("/system-tickets/{$ticket->id}/conversation")->assertOk()->assertJsonPath('messages.data.0.body', 'More details');
        $this->actingAs($admin)->postJson("/system-tickets/{$ticket->id}/messages", ['body' => 'Working on it'])->assertCreated()->assertJsonPath('message.is_support', true);
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertSame('Original details', $ticket->fresh()->description);
        $this->assertDatabaseHas('system_ticket_events', ['ticket_id' => $ticket->id, 'kind' => 'first_response']);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_archived_tickets_are_read_only_and_invalid_messages_rejected(): void
    {
        $sender = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $ticket = $this->ticket($sender);
        $this->actingAs($sender)->postJson("/system-tickets/{$ticket->id}/messages", ['body' => '  '])->assertUnprocessable();
        $ticket->update(['archived_at' => now()]);
        $this->postJson("/system-tickets/{$ticket->id}/messages", ['body' => 'Reply'])->assertUnprocessable();
        $this->getJson("/system-tickets/{$ticket->id}/conversation")->assertOk();
    }

    public function test_reports_and_publication_are_admin_only_and_separate_from_private_content(): void
    {
        $sender = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $ticket = $this->ticket($sender);
        $report = ['issue_summary' => 'Private issue', 'root_cause' => 'Cause', 'resolution' => 'Fix', 'verification' => 'Checked', 'references' => 'Internal reference', 'follow_up' => 'None', 'documentation_summary' => 'Generic summary'];
        $this->actingAs($sender)->postJson("/admin/system-tickets/{$ticket->id}/resolution", ['report' => $report])->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->postJson("/admin/system-tickets/{$ticket->id}/resolution", ['report' => $report])->assertOk();
        $this->postJson("/admin/system-tickets/{$ticket->id}/publish", ['title' => 'Guide', 'body' => 'Generic help'])->assertUnprocessable();
        $this->postJson("/admin/system-tickets/{$ticket->id}/publish", ['title' => 'Guide', 'body' => 'Generic help', 'reviewed' => true])->assertOk();
        $this->assertSame('Generic help', $ticket->fresh()->documentation_body);
        $this->assertSame('Private issue', $ticket->fresh()->resolution_report['issue_summary']);
        $this->actingAs($sender)->get("/system-tickets/{$ticket->id}/resolution")->assertOk()->assertDownload();
    }

    public function test_inactive_and_lead_senders_cannot_access_and_support_own_reply_is_not_first_response(): void
    {
        foreach (['lead', 'revoked_lead', 'sales'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => $role !== 'sales']);
            $ticket = $this->ticket($user);
            $this->actingAs($user)->getJson("/system-tickets/{$ticket->id}/conversation")->assertForbidden();
        }
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $ticket = $this->ticket($admin);
        $this->actingAs($admin)->postJson("/system-tickets/{$ticket->id}/messages", ['body' => 'My own update'])->assertCreated();
        $this->assertDatabaseMissing('system_ticket_events', ['ticket_id' => $ticket->id, 'kind' => 'first_response']);
    }

    public function test_conversation_pages_are_bounded_and_ordered_and_do_not_expose_user_emails(): void
    {
        $sender = User::factory()->create(['role' => 'sales', 'is_active' => true]);
        $ticket = $this->ticket($sender);
        for ($i = 1; $i <= 35; $i++) {
            $ticket->messages()->create(['user_id' => $sender->id, 'body' => "Reply {$i}", 'is_support' => false]);
        }
        $this->actingAs($sender)->getJson("/system-tickets/{$ticket->id}/conversation")
            ->assertOk()->assertJsonCount(30, 'messages.data')
            ->assertJsonPath('messages.data.0.body', 'Reply 6')
            ->assertJsonPath('messages.data.29.body', 'Reply 35')
            ->assertJsonMissingPath('messages.data.0.user.email');
        $this->getJson("/system-tickets/{$ticket->id}/conversation?page=2")
            ->assertOk()->assertJsonCount(5, 'messages.data')->assertJsonPath('messages.data.0.body', 'Reply 1');
    }

    public function test_archived_report_and_publication_cannot_be_changed(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $ticket = $this->ticket($admin);
        $report = ['issue_summary' => 'Issue', 'root_cause' => 'Cause', 'resolution' => 'Fixed', 'verification' => 'Tested', 'documentation_summary' => 'Guide'];
        $ticket->update(['archived_at' => now(), 'resolution_report' => $report, 'documentation_title' => 'Existing', 'documentation_body' => 'Existing body']);
        $this->actingAs($admin)->postJson("/admin/system-tickets/{$ticket->id}/resolution", ['report' => array_merge($report, ['resolution' => 'Changed'])])->assertUnprocessable();
        $this->postJson("/admin/system-tickets/{$ticket->id}/publish", ['title' => 'Changed', 'body' => 'Changed', 'reviewed' => true])->assertUnprocessable();
        $this->assertSame('Fixed', $ticket->fresh()->resolution_report['resolution']);
        $this->assertSame('Existing body', $ticket->fresh()->documentation_body);
        $this->assertDatabaseMissing('system_ticket_events', ['ticket_id' => $ticket->id, 'kind' => 'report_saved']);
        $this->assertDatabaseMissing('system_ticket_events', ['ticket_id' => $ticket->id, 'kind' => 'documentation_published']);
    }

    public function test_report_and_publication_are_audited_and_download_contains_ticket_metadata(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'name' => 'Assigned Admin']);
        $resolver = User::factory()->create(['role' => 'admin', 'is_active' => true, 'name' => 'Resolving Admin']);
        $ticket = $this->ticket($admin);
        $ticket->update(['assigned_to' => $admin->id, 'resolved_by' => $resolver->id, 'resolved_at' => '2026-10-20 12:00:00']);
        $report = ['issue_summary' => 'Issue', 'root_cause' => 'Cause', 'resolution' => 'Fixed', 'verification' => 'Tested', 'documentation_summary' => 'Guide'];
        $this->actingAs($admin)->postJson("/admin/system-tickets/{$ticket->id}/resolution", ['report' => $report])->assertOk();
        $this->postJson("/admin/system-tickets/{$ticket->id}/publish", ['title' => 'Guide', 'body' => 'Generic help', 'reviewed' => true])->assertOk();
        $this->assertDatabaseHas('system_ticket_events', ['ticket_id' => $ticket->id, 'kind' => 'report_saved', 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('system_ticket_events', ['ticket_id' => $ticket->id, 'kind' => 'documentation_published', 'actor_id' => $admin->id]);
        $content = $this->get("/system-tickets/{$ticket->id}/resolution")->assertOk()->streamedContent();
        foreach (['Private issue', 'Assigned Admin', 'Resolving Admin', $ticket->created_at->toIso8601String(), '2026-10-20T12:00:00'] as $value) {
            $this->assertStringContainsString($value, $content);
        }
    }
}
