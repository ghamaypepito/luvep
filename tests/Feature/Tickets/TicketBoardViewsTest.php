<?php

namespace Tests\Feature\Tickets;

use App\Models\SystemTicket;
use App\Models\SystemTicketEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketBoardViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ticket(User $user, array $attributes = []): SystemTicket
    {
        return SystemTicket::create(['title' => 'Synthetic board ticket', 'description' => 'Synthetic details', 'category' => 'bug', 'priority' => 'normal', 'status' => 'open', 'submitted_by' => $user->id, 'department' => 'education', ...$attributes]);
    }

    public function test_each_status_is_exact_all_excludes_done_and_archive_is_isolated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ids = [];
        foreach (SystemTicket::STATUSES as $status) {
            $ids[$status] = $this->ticket($admin, ['status' => $status])->id;
            $this->ticket($admin, ['status' => $status, 'archived_at' => now()]);
        }
        $this->actingAs($admin)->get('/admin/system-tickets')->assertOk()->assertInertia(fn (Assert $p) => $p->has('tickets.data', 1)->where('tickets.data.0.id', $ids['open'])->where('filters.status', 'open'));
        foreach ($ids as $status => $id) {
            $this->get('/admin/system-tickets?status='.$status)->assertOk()->assertInertia(fn (Assert $p) => $p->has('tickets.data', 1)->where('tickets.data.0.id', $id));
        }
        $this->get('/admin/system-tickets?status=all')->assertOk()->assertInertia(fn (Assert $p) => $p->has('tickets.data', 5)->where('tickets.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['status'] !== 'done' && ! $row['archived_at'])));
        $this->get('/admin/system-tickets?tab=archived&status=open')->assertOk()->assertInertia(fn (Assert $p) => $p->has('tickets.data', 6)->where('tickets.data', fn ($rows) => collect($rows)->every(fn ($row) => (bool) $row['archived_at'])));
        $this->get('/admin/system-tickets?status=all&search=Synthetic')->assertOk()->assertInertia(fn (Assert $p) => $p->has('tickets.data', 5));
        $this->assertSame(4, SystemTicket::dashboardSummary()['open_count']);
    }

    public function test_admin_archive_preserves_the_ticket_and_is_audited_and_idempotent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $sender = User::factory()->create(['role' => 'education']);
        $ticket = $this->ticket($sender, ['assigned_to' => $admin->id, 'resolution_report' => ['resolution' => 'Original report'], 'images' => ['private/screenshot.png']]);
        $url = '/admin/system-tickets/'.$ticket->id.'/archive';
        $this->actingAs($sender)->postJson($url, ['expected_status' => 'open'])->assertForbidden();
        $this->actingAs($admin)->post($url, ['expected_status' => 'in_review'])->assertSessionHasErrors('status');
        $this->assertNull($ticket->fresh()->archived_at);
        $this->post($url, ['expected_status' => 'open'])->assertRedirect()->assertSessionHasNoErrors();
        $saved = $ticket->fresh();
        $this->assertNotNull($saved->archived_at);
        $this->assertSame('open', $saved->status);
        $this->assertSame($admin->id, $saved->assigned_to);
        $this->assertSame(['private/screenshot.png'], $saved->images);
        $this->assertSame('Original report', $saved->resolution_report['resolution']);
        $this->assertNull($saved->resolved_at);
        $this->assertDatabaseHas('system_ticket_events', ['ticket_id' => $ticket->id, 'kind' => 'archived', 'actor_id' => $admin->id]);
        $this->post($url, ['expected_status' => 'open'])->assertSessionHasNoErrors();
        $this->assertSame(1, SystemTicketEvent::where('ticket_id', $ticket->id)->where('kind', 'archived')->count());
        $this->post('/admin/system-tickets/'.$ticket->id, ['status' => 'done'])->assertStatus(422);
    }

    public function test_retention_is_two_calendar_months_inclusive_and_repeatable(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-15 04:00:00', 'UTC'));
        $user = User::factory()->create(['role' => 'education']);
        $cutoff = now('Asia/Manila')->subMonthsNoOverflow(2)->utc();
        $old = $this->ticket($user, ['status' => 'done', 'resolved_at' => $cutoff->copy()->subSecond()]);
        $boundary = $this->ticket($user, ['status' => 'done', 'resolved_at' => $cutoff]);
        $recent = $this->ticket($user, ['status' => 'done', 'resolved_at' => $cutoff->copy()->addSecond()]);
        $unknown = $this->ticket($user, ['status' => 'done']);
        $declined = $this->ticket($user, ['status' => 'declined', 'resolved_at' => $cutoff->copy()->subMonth()]);
        $this->artisan('tickets:archive --dry-run')->assertSuccessful();
        $this->assertSame(0, SystemTicket::whereNotNull('archived_at')->count());
        $this->artisan('tickets:archive')->assertSuccessful();
        $this->assertNotNull($old->fresh()->archived_at);
        $this->assertNotNull($boundary->fresh()->archived_at);
        foreach ([$recent, $unknown, $declined] as $ticket) {
            $this->assertNull($ticket->fresh()->archived_at);
        }
        $this->assertTrue($boundary->fresh()->resolved_at->equalTo($cutoff));
        $this->artisan('tickets:archive')->assertSuccessful();
        $this->assertSame(2, SystemTicketEvent::where('kind', 'archived')->count());
        $this->assertSame(5, SystemTicket::count());
    }

    public function test_stale_status_change_cannot_close_a_ticket_that_moved_to_another_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket($admin, ['status' => 'in_progress']);
        $this->actingAs($admin)->post('/admin/system-tickets/'.$ticket->id, ['status' => 'declined', 'expected_status' => 'open'])->assertSessionHasErrors('status');
        $this->assertSame('in_progress', $ticket->fresh()->status);
    }

    public function test_archives_leave_current_workload_but_preserve_historical_totals(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $open = $this->ticket($admin);
        $this->ticket($admin, ['archived_at' => now()]);
        $this->ticket($admin, ['status' => 'done', 'resolved_at' => now()->subMonths(3), 'archived_at' => now()]);
        $this->actingAs($admin)->get('/admin/system-tickets/analytics')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('metrics.created', 3)->where('metrics.resolved', 1)->where('metrics.unresolved', 1)
            ->has('timings', 1)->where('timings.0.ticket_ref', $open->ticket_ref)->where('workload.0.open', 1));
    }

    public function test_automatic_archive_rechecks_a_reopened_ticket_and_respects_soft_deletes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $reopened = $this->ticket($admin, ['status' => 'in_progress', 'resolved_at' => now()->subMonths(3)]);
        $deleted = $this->ticket($admin, ['status' => 'done', 'resolved_at' => now()->subMonths(3)]);
        $deleted->delete();
        $archiver = app(\App\Services\Tickets\TicketArchiver::class);
        $this->assertFalse($archiver->whenDue($reopened->id, now()));
        $this->assertFalse($archiver->whenDue($deleted->id, now()));
        $this->assertNull($reopened->fresh()->archived_at);
        $this->assertSame(0, SystemTicketEvent::where('kind', 'archived')->count());
    }

    public function test_month_end_anniversaries_archive_without_waiting_for_an_extra_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-02-28 04:00:00', 'UTC'));
        $user = User::factory()->create(['role' => 'education']);
        foreach ([28, 29, 30, 31] as $day) {
            $this->ticket($user, ['status' => 'done', 'resolved_at' => Carbon::parse("2026-12-{$day} 12:00:00", 'Asia/Manila')->utc()]);
        }
        $notDue = $this->ticket($user, ['status' => 'done', 'resolved_at' => Carbon::parse('2026-12-31 12:00:01', 'Asia/Manila')->utc()]);
        $this->artisan('tickets:archive --dry-run')->expectsOutput('Eligible completed tickets: 4.')->assertSuccessful();
        $this->assertSame(0, SystemTicket::whereNotNull('archived_at')->count());
        $this->artisan('tickets:archive')->expectsOutput('Archived 4 completed tickets.')->assertSuccessful();
        $this->assertNull($notDue->fresh()->archived_at);
        Carbon::setTestNow(Carbon::parse('2027-02-28 04:00:01', 'UTC'));
        $this->artisan('tickets:archive')->expectsOutput('Archived 1 completed tickets.')->assertSuccessful();
        $this->assertSame(5, SystemTicket::whereNotNull('archived_at')->count());
    }
}
