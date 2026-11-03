<?php

namespace Tests\Feature\Tickets;

use App\Models\SystemTicket;
use App\Models\SystemTicketEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TicketSenderEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function ticket(User $owner, array $extra = []): SystemTicket
    {
        return SystemTicket::create(array_merge(['title' => 'Original request', 'description' => 'Original details',
            'category' => 'bug', 'priority' => 'normal', 'status' => 'open',
            'submitted_by' => $owner->id, 'department' => $owner->role], $extra));
    }

    private function version(SystemTicket $ticket): string
    {
        return hash('sha256', json_encode($ticket->only(['title', 'description', 'category', 'priority', 'images', 'attachments'])));
    }

    private function payload(SystemTicket $ticket, array $extra = []): array
    {
        return array_merge(['title' => 'Updated request', 'description' => 'Updated details', 'category' => 'change',
            'priority' => 'high', 'request_version' => $this->version($ticket)], $extra);
    }

    public static function senderRoles(): array
    {
        return array_map(fn ($role) => [$role], ['education', 'immigration_manager', 'sales', 'admin', 'super_admin']);
    }

    #[DataProvider('senderRoles')]
    public function test_sender_can_edit_request_with_attributed_history_and_preserved_support_fields(string $role): void
    {
        $owner = User::factory()->create(['role' => $role]);
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket($owner, ['assigned_to' => $admin->id, 'admin_response' => 'Support response']);
        $this->actingAs($owner)->post("/system-tickets/{$ticket->id}/request", $this->payload($ticket, [
            'status' => 'done', 'submitted_by' => $admin->id, 'assigned_to' => $owner->id, 'admin_response' => 'Forged',
            'resolution_report' => ['resolution' => 'Forged'], 'department' => 'admin',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertSame('Updated request', $ticket->title);
        $this->assertSame('Updated details', $ticket->description);
        $this->assertSame('change', $ticket->category);
        $this->assertSame('high', $ticket->priority);
        $this->assertSame('open', $ticket->status);
        $this->assertSame($owner->id, $ticket->submitted_by);
        $this->assertSame($admin->id, $ticket->assigned_to);
        $this->assertSame('Support response', $ticket->admin_response);
        $this->assertNull($ticket->resolution_report);
        $this->assertSame($role, $ticket->department);
        $event = $ticket->events()->where('kind', 'request_updated')->sole();
        $this->assertSame($owner->id, $event->actor_id);
        $this->assertSame(['from' => 'Original details', 'to' => 'Updated details'], $event->metadata['changes']['description']);
        $this->assertSame(0, $event->metadata['attachments_added']);
    }

    public function test_other_staff_and_even_admin_cannot_edit_another_senders_request(): void
    {
        $ticket = $this->ticket(User::factory()->create(['role' => 'education']));
        foreach (['education', 'admin', 'super_admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post("/system-tickets/{$ticket->id}/request", $this->payload($ticket))->assertForbidden();
        }
        $this->assertSame('Original request', $ticket->fresh()->title);
        $this->assertDatabaseCount('system_ticket_events', 0);
    }

    public function test_external_and_archived_owners_cannot_edit(): void
    {
        foreach (['lead', 'revoked_lead'] as $role) {
            $owner = User::factory()->create(['role' => $role]);
            $ticket = $this->ticket($owner);
            $this->actingAs($owner)->post("/system-tickets/{$ticket->id}/request", $this->payload($ticket))->assertForbidden();
        }
        $owner = User::factory()->create(['role' => 'education']);
        $ticket = $this->ticket($owner, ['archived_at' => now()]);
        $this->actingAs($owner)->post("/system-tickets/{$ticket->id}/request", $this->payload($ticket))->assertStatus(422);
        $this->assertSame('Original request', $ticket->fresh()->title);
    }

    public function test_done_and_declined_tickets_are_editable_without_reopening_or_rewriting_resolution(): void
    {
        $owner = User::factory()->create(['role' => 'education']);
        foreach (['done', 'declined'] as $status) {
            $ticket = $this->ticket($owner, ['status' => $status, 'resolved_at' => now()->subDay(),
                'resolved_by' => $owner->id, 'resolution_report' => ['resolution' => 'Original support resolution']]);
            $resolvedAt = $ticket->resolved_at->toIso8601String();
            $this->actingAs($owner)->post("/system-tickets/{$ticket->id}/request", $this->payload($ticket))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($status, $ticket->fresh()->status);
            $this->assertSame($resolvedAt, $ticket->resolved_at->toIso8601String());
            $this->assertSame(['resolution' => 'Original support resolution'], $ticket->resolution_report);
        }
    }

    public function test_stale_content_edit_is_rejected_but_support_triage_does_not_conflict(): void
    {
        $owner = User::factory()->create(['role' => 'education']);
        $ticket = $this->ticket($owner);
        $oldPayload = $this->payload($ticket);
        $ticket->update(['status' => 'in_progress', 'admin_response' => 'Working on it']);
        $this->actingAs($owner)->post("/system-tickets/{$ticket->id}/request", $oldPayload)->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson("/system-tickets/{$ticket->id}/request", array_merge($oldPayload, ['title' => 'Stale overwrite']))
            ->assertUnprocessable()->assertJsonValidationErrors('request_version');
        $this->assertSame('Updated request', $ticket->fresh()->title);
        $this->assertCount(1, $ticket->events()->where('kind', 'request_updated')->get());
    }

    public function test_append_preserves_existing_evidence_and_stores_new_files_privately(): void
    {
        $owner = User::factory()->create(['role' => 'education']);
        $existing = ['path' => 'tickets/original.txt', 'name' => 'original.txt', 'mime' => 'text/plain', 'size' => 3];
        Storage::disk('local')->put($existing['path'], 'old');
        $ticket = $this->ticket($owner, ['images' => ['tickets/legacy.png'], 'attachments' => [$existing]]);
        $this->actingAs($owner)->post("/system-tickets/{$ticket->id}/request", $this->payload($ticket, [
            'attachments' => [UploadedFile::fake()->image('new.png'), UploadedFile::fake()->createWithContent('details.txt', 'Updated supporting details')],
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertSame(['tickets/legacy.png'], $ticket->images);
        $this->assertCount(3, $ticket->attachments);
        $this->assertSame($existing, $ticket->attachments[0]);
        foreach ($ticket->attachments as $file) {
            Storage::disk('local')->assertExists($file['path']);
            Storage::disk('public')->assertMissing($file['path']);
        }
        $event = $ticket->events()->where('kind', 'request_updated')->sole();
        $this->assertSame(2, $event->metadata['attachments_added']);
        $this->assertArrayNotHasKey('attachments', $event->metadata['changes']);
    }

    public function test_combined_attachment_limit_and_invalid_input_leave_request_and_files_unchanged(): void
    {
        $owner = User::factory()->create(['role' => 'education']);
        $ticket = $this->ticket($owner, ['images' => array_fill(0, 5, 'old.png'),
            'attachments' => array_fill(0, 5, ['path' => 'old.txt', 'name' => 'old.txt', 'mime' => 'text/plain', 'size' => 1])]);
        $this->actingAs($owner)->postJson("/system-tickets/{$ticket->id}/request", $this->payload($ticket, ['attachments' => [UploadedFile::fake()->image('overflow.png')]]))
            ->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $this->postJson("/system-tickets/{$ticket->id}/request", $this->payload($ticket, ['title' => '', 'category' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'category']);
        $this->assertSame('Original request', $ticket->fresh()->title);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('system_ticket_events', 0);
    }

    public function test_permission_and_content_version_are_serialized_for_owner_only_and_noop_does_not_audit(): void
    {
        $owner = User::factory()->create(['role' => 'education']);
        $ticket = $this->ticket($owner);
        $this->actingAs($owner)->get('/portal/tickets')->assertInertia(fn (Assert $page) => $page
            ->where('tickets.data.0.can_edit_request', true)
            ->where('tickets.data.0.request_version', $this->version($ticket)));
        $this->post("/system-tickets/{$ticket->id}/request", $this->payload($ticket, $ticket->only(['title', 'description', 'category', 'priority'])))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('system_ticket_events', 0);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/system-tickets')->assertInertia(fn (Assert $page) => $page
            ->where('tickets.data.0.can_edit_request', false)->where('tickets.data.0.request_version', null));
    }

    public function test_inactive_sender_cannot_edit_and_own_admin_ticket_is_editable_on_admin_board(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket($owner, ['status' => 'done']);
        $this->actingAs($owner)->get('/admin/system-tickets')->assertInertia(fn (Assert $page) => $page
            ->where('tickets.data.0.can_edit_request', true)->where('tickets.data.0.request_version', $this->version($ticket)));
        $owner->update(['is_active' => false]);
        $this->post("/system-tickets/{$ticket->id}/request", $this->payload($ticket))->assertRedirect('/login');
        $this->assertSame('Original request', $ticket->fresh()->title);
    }

    public function test_failed_audit_rolls_back_request_and_cleans_only_new_attachments(): void
    {
        $owner = User::factory()->create(['role' => 'education']);
        $old = ['path' => 'tickets/original.txt', 'name' => 'original.txt', 'mime' => 'text/plain', 'size' => 3];
        Storage::disk('local')->put($old['path'], 'old');
        $ticket = $this->ticket($owner, ['attachments' => [$old]]);
        $dispatcher = SystemTicketEvent::getEventDispatcher();
        SystemTicketEvent::setEventDispatcher(clone $dispatcher);
        SystemTicketEvent::creating(function () {
            throw new \RuntimeException('Simulated event failure');
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($owner)->post("/system-tickets/{$ticket->id}/request", $this->payload($ticket, [
                'attachments' => [UploadedFile::fake()->image('new.png')],
            ]));
            $this->fail('The simulated audit failure must surface.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated event failure', $exception->getMessage());
        } finally {
            SystemTicketEvent::setEventDispatcher($dispatcher);
        }
        $this->assertSame('Original request', $ticket->fresh()->title);
        $this->assertSame([$old], $ticket->attachments);
        $this->assertSame([$old['path']], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('system_ticket_events', 0);
    }

    public function test_edited_admin_request_is_returned_when_it_leaves_the_current_search(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket($owner);
        $url = '/admin/system-tickets?search=Original';
        $this->actingAs($owner)->from($url)->post("/system-tickets/{$ticket->id}/request", $this->payload($ticket))
            ->assertRedirect($url)->assertSessionHasNoErrors();
        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->has('tickets.data', 0)->where('updated_request.id', $ticket->id)
            ->where('updated_request.title', 'Updated request')
            ->where('updated_request.request_version', $this->version($ticket->fresh()))
            ->where('updated_request.can_edit_request', true)
            ->where('updated_request.request_edited_at', fn ($at) => is_string($at) && $at !== ''));
    }

    public function test_updated_request_flash_is_reauthorized_for_the_current_user(): void
    {
        $owner = User::factory()->create(['role' => 'education']);
        $ticket = $this->ticket($owner);
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($other)->withSession(['ticket_request_update' => ['actor_id' => $other->id, 'ticket_id' => $ticket->id]])
            ->get('/admin/system-tickets')->assertInertia(fn (Assert $page) => $page->where('updated_request', null));
        $this->withSession(['ticket_request_update' => ['actor_id' => $owner->id, 'ticket_id' => $ticket->id]])
            ->get('/admin/system-tickets')->assertInertia(fn (Assert $page) => $page->where('updated_request', null));
    }
}
