<?php

namespace Tests\Feature\Tickets;

use App\Models\SystemTicket;
use App\Models\User;
use App\Notifications\TicketSubmitted;
use App\Notifications\TicketUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemTicketTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $o = []): array
    {
        return array_merge([
            'title'       => 'Add a bulk export button',
            'description' => 'We need to export the leads list to CSV from the sales portal.',
            'category'    => 'feature',
            'priority'    => 'high',
        ], $o);
    }

    public function test_staff_can_submit_a_ticket(): void
    {
        Notification::fake();
        $sales = User::factory()->create(['role' => 'sales']);

        $this->actingAs($sales)->post('/tickets', $this->payload())->assertRedirect();

        $this->assertDatabaseHas('system_tickets', [
            'title' => 'Add a bulk export button', 'department' => 'sales', 'status' => 'open', 'submitted_by' => $sales->id,
        ]);
        $this->assertStringStartsWith('TKT-', SystemTicket::first()->ticket_ref);
    }

    public function test_submission_notifies_admins_and_super_admins(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $super = User::factory()->create(['role' => 'super_admin']);
        $sales = User::factory()->create(['role' => 'sales']);

        $this->actingAs($sales)->post('/tickets', $this->payload());

        Notification::assertSentTo($admin, TicketSubmitted::class);
        Notification::assertSentTo($super, TicketSubmitted::class);
        Notification::assertNotSentTo($sales, TicketSubmitted::class);
    }

    public function test_lead_cannot_submit(): void
    {
        $lead = User::factory()->create(['role' => 'lead']);
        $this->actingAs($lead)->post('/tickets', $this->payload())->assertForbidden();
    }

    public function test_validation_requires_title_description_category(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->post('/tickets', ['priority' => 'normal'])
            ->assertSessionHasErrors(['title', 'description', 'category']);
    }

    public function test_admin_can_list_all_tickets(): void
    {
        SystemTicket::create($this->payload(['submitted_by' => User::factory()->create(['role' => 'sales'])->id, 'department' => 'sales']));

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/system-tickets')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/SystemTickets')->has('tickets.data', 1));
    }

    public function test_super_admin_can_access_board(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->get('/admin/system-tickets')->assertOk();
    }

    public function test_non_admin_cannot_access_board(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->get('/admin/system-tickets')->assertForbidden();
    }

    public function test_staff_see_only_their_own_tickets(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $other = User::factory()->create(['role' => 'education']);
        SystemTicket::create($this->payload(['submitted_by' => $sales->id, 'department' => 'sales']));
        SystemTicket::create($this->payload(['submitted_by' => $other->id, 'department' => 'education']));

        $this->actingAs($sales)
            ->get('/portal/tickets')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('portal/sales/Tickets')->has('tickets.data', 1));
    }

    public function test_my_tickets_redirects_admins_to_the_board(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/portal/tickets')
            ->assertRedirect('/admin/system-tickets');
    }

    public function test_lead_cannot_view_my_tickets(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'lead']))
            ->get('/portal/tickets')->assertForbidden();
    }

    public function test_admin_update_changes_status_and_notifies_submitter(): void
    {
        Notification::fake();
        $sales = User::factory()->create(['role' => 'sales']);
        $ticket = SystemTicket::create($this->payload(['submitted_by' => $sales->id, 'department' => 'sales']));
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post("/admin/system-tickets/{$ticket->id}", [
            'status' => 'in_progress', 'admin_response' => 'Picking this up this sprint.',
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame('in_progress', $ticket->status);
        $this->assertSame('Picking this up this sprint.', $ticket->admin_response);
        Notification::assertSentTo($sales, TicketUpdated::class);
    }

    public function test_resolving_stamps_resolver_and_time(): void
    {
        Notification::fake();
        $sales = User::factory()->create(['role' => 'sales']);
        $ticket = SystemTicket::create($this->payload(['submitted_by' => $sales->id, 'department' => 'sales']));
        $admin = User::factory()->create(['role' => 'admin']);

        $ticket->update(['resolution_report' => array_fill_keys(['issue_summary', 'root_cause', 'resolution', 'verification', 'documentation_summary'], 'Verified resolution')]);

        $this->actingAs($admin)->post("/admin/system-tickets/{$ticket->id}", ['status' => 'done']);

        $ticket->refresh();
        $this->assertSame($admin->id, $ticket->resolved_by);
        $this->assertNotNull($ticket->resolved_at);
    }

    public function test_ticket_can_be_submitted_with_screenshots(): void
    {
        Notification::fake();
        Storage::fake('local');
        $sales = User::factory()->create(['role' => 'sales']);

        $this->actingAs($sales)->post('/tickets', $this->payload([
            'images' => [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.jpg')],
        ]))->assertRedirect();

        $ticket = SystemTicket::first();
        $this->assertCount(2, $ticket->images);
        foreach ($ticket->images as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_more_than_five_images_is_rejected(): void
    {
        Storage::fake('local');
        $sales = User::factory()->create(['role' => 'sales']);

        $this->actingAs($sales)->post('/tickets', $this->payload([
            'images' => array_map(fn ($i) => UploadedFile::fake()->image("s{$i}.png"), range(1, 6)),
        ]))->assertSessionHasErrors('images');

        $this->assertSame(0, SystemTicket::count());
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Storage::fake('local');
        $sales = User::factory()->create(['role' => 'sales']);

        $this->actingAs($sales)->post('/tickets', $this->payload([
            'images' => [UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf')],
        ]))->assertSessionHasErrors('images.0');
    }

    public function test_submitter_and_admin_can_stream_a_screenshot_but_other_staff_cannot(): void
    {
        Notification::fake();
        Storage::fake('local');
        $sales = User::factory()->create(['role' => 'sales']);

        $this->actingAs($sales)->post('/tickets', $this->payload([
            'images' => [UploadedFile::fake()->image('a.png')],
        ]));
        $ticket = SystemTicket::first();

        // Owner can view.
        $this->actingAs($sales)->get("/system-tickets/{$ticket->id}/images/0")->assertOk();
        // Admin (triager) can view.
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get("/system-tickets/{$ticket->id}/images/0")->assertOk();
        // An unrelated staff member cannot.
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->get("/system-tickets/{$ticket->id}/images/0")->assertForbidden();
        // A lead cannot.
        $this->actingAs(User::factory()->create(['role' => 'lead']))
            ->get("/system-tickets/{$ticket->id}/images/0")->assertForbidden();
        // Out-of-range index 404s.
        $this->actingAs($sales)->get("/system-tickets/{$ticket->id}/images/9")->assertNotFound();
    }
}
