<?php

namespace Tests\Feature;

use App\Models\SystemTicket;
use App\Models\User;
use App\Notifications\SupportTicketCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
    }

    private function ticket($user, $attrs = [])
    {
        return SystemTicket::create(array_merge(['title' => 'Example', 'description' => 'Details', 'category' => 'bug', 'priority' => 'urgent', 'status' => 'open', 'submitted_by' => $user->id, 'department' => 'sales', 'tracking_started_at' => now()], $attrs));
    }

    public function test_created_ticket_emails_support_with_sender_urgency_and_assignment(): void
    {
        $u = User::factory()->create(['role' => 'sales']);
        $this->actingAs($u)->post('/tickets', ['title' => 'Cannot save', 'description' => 'Example', 'category' => 'bug', 'priority' => 'urgent'])->assertRedirect();
        Notification::assertSentOnDemand(SupportTicketCreated::class, fn ($n, $channels, $recipient) => $recipient->routes['mail'] === 'support@luvep.com' && $n->ticket->priority === 'urgent');
        $mail = (new SupportTicketCreated(SystemTicket::first()))->toMail(new \stdClass);
        $this->assertStringContainsString('URGENT', $mail->subject);
        $this->assertStringContainsString($u->email, implode(' ', $mail->introLines));
        $this->assertStringContainsString('Unassigned', implode(' ', $mail->introLines));
    }

    public function test_done_requires_report_and_transitions_are_recorded(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $t = $this->ticket($admin);
        $this->actingAs($admin)->post('/admin/system-tickets/'.$t->id, ['status' => 'done'])->assertSessionHasErrors('status');
        $t->update(['resolution_report' => array_fill_keys(['issue_summary', 'root_cause', 'resolution', 'verification', 'documentation_summary'], 'Verified')]);
        $this->post('/admin/system-tickets/'.$t->id, ['status' => 'done', 'assigned_to' => $admin->id])->assertRedirect()->assertSessionHasNoErrors();
        $resolved = $t->fresh()->resolved_at;
        $this->travel(2)->days();
        $this->post('/admin/system-tickets/'.$t->id, ['status' => 'done', 'topic' => 'Login'])->assertSessionHasNoErrors();
        $this->assertTrue($resolved->equalTo($t->fresh()->resolved_at));
        $this->post('/admin/system-tickets/'.$t->id, ['status' => 'open'])->assertSessionHasNoErrors();
        $this->assertNull($t->fresh()->resolved_at);
        $this->assertDatabaseHas('system_ticket_events', ['ticket_id' => $t->id, 'kind' => 'status_changed', 'from_status' => 'done', 'to_status' => 'open']);
    }

    public function test_archive_only_old_done_tickets_and_preserves_records(): void
    {
        $u = User::factory()->create();
        $old = $this->ticket($u, ['status' => 'done', 'resolved_at' => now()->subMonths(4)]);
        $recent = $this->ticket($u, ['status' => 'done', 'resolved_at' => now()->subMonths(2)]);
        $open = $this->ticket($u, ['created_at' => now()->subMonths(5)]);
        $unknown = $this->ticket($u, ['status' => 'done']);
        $this->artisan('tickets:archive')->assertSuccessful();
        $this->assertNotNull($old->fresh()->archived_at);
        foreach ([$recent, $open, $unknown] as $t) {
            $this->assertNull($t->fresh()->archived_at);
        }$this->assertDatabaseCount('system_tickets', 4);
    }

    public function test_analytics_excludes_unverifiable_legacy_response_latency_and_blocks_staff(): void
    {
        $u = User::factory()->create(['role' => 'sales']);
        $a = User::factory()->create(['role' => 'admin']);
        $t = $this->ticket($u, ['created_at' => now()->subDays(30)]);
        $t->forceFill(['created_at' => now()->subDays(30)])->save();
        $t->messages()->create(['user_id' => $a->id, 'body' => 'Reply', 'is_support' => true]);
        $this->actingAs($u)->get('/admin/system-tickets/analytics')->assertForbidden();
        $this->actingAs($a)->get('/admin/system-tickets/analytics')->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/TicketAnalytics')->where('metrics.created', 1)->where('metrics.first_response_avg_hours', null));
    }

    public function test_waiting_time_excludes_closed_intervals_after_reopening(): void
    {
        $this->travelTo(now()->startOfDay());
        $admin = User::factory()->create(['role' => 'admin']);
        $t = $this->ticket($admin, ['tracking_started_at' => now()->subDays(12), 'waiting_on' => 'support']);
        $t->forceFill(['created_at' => now()->subDays(12)])->save();
        $t->events()->create(['kind' => 'status_changed', 'from_status' => 'open', 'to_status' => 'done', 'created_at' => now()->subDays(11)]);
        $t->events()->create(['kind' => 'status_changed', 'from_status' => 'done', 'to_status' => 'open', 'created_at' => now()->subDays(1)]);
        $this->actingAs($admin)->get('/admin/system-tickets/analytics')->assertOk()->assertInertia(fn (Assert $p) => $p->where('timings.0.waiting_hours.support',48)->where('timings.0.status_hours.open',48)->missing('timings.0.status_hours.done'));
    }
}
