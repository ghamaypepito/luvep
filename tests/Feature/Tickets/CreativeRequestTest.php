<?php

namespace Tests\Feature\Tickets;

use App\Models\CreativeRequest;
use App\Models\HrEmployee;
use App\Models\LeadTask;
use App\Models\User;
use App\Notifications\CreativeRequestAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreativeRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['creative.assignee_email' => 'creative-owner@example.test', 'creative.notification_email' => 'creative-alert@example.test']);
        $this->withoutVite();
        Notification::fake();
        Storage::fake('local');
    }

    private function owner(): User
    {
        $owner = User::factory()->create(['name' => 'Sharry Creative', 'email' => 'creative-owner@example.test', 'role' => 'admin']);

        return $owner;
    }

    private function brief(array $extra = []): array
    {
        return [...['project' => 'Study campaign', 'deadline' => now()->addDays(7)->toDateString(), 'overview' => 'Create an intake graphic.', 'types' => ['Social media graphic'], 'caption' => 'Start your next chapter. #StudyInNZ', 'approver' => 'Marketing lead'], ...$extra];
    }

    public function test_creation_preserves_caption_and_assigns_one_task_and_notification(): void
    {
        $owner = $this->owner();
        $sender = User::factory()->create(['role' => 'sales']);
        $this->actingAs($sender)->post('/creative-requests', $this->brief())->assertRedirect();
        $request = CreativeRequest::firstOrFail();
        $this->assertSame('Start your next chapter. #StudyInNZ', $request->brief['caption']);
        $this->assertSame($owner->id, $request->task->assignee_id);
        $this->assertSame($sender->id, $request->task->created_by);
        $this->assertSame('not_started', $request->task->status);
        $this->assertSame(1, LeadTask::count());
        Notification::assertSentTo($owner, CreativeRequestAssigned::class);
        $this->assertDatabaseCount('system_tickets', 0);
    }

    public function test_missing_or_inactive_owner_never_silently_routes_a_request(): void
    {
        $sender = User::factory()->create(['role' => 'sales']);
        $this->actingAs($sender)->post('/creative-requests', $this->brief())->assertSessionHasErrors('assignee');
        $owner = $this->owner();
        $owner->update(['is_active' => false]);
        $this->post('/creative-requests', $this->brief())->assertSessionHasErrors('assignee');
        $this->assertDatabaseCount('lead_tasks', 0);
    }

    public function test_email_alert_uses_the_requested_address_and_a_private_brief_link(): void
    {
        $owner = $this->owner();
        $this->actingAs(User::factory()->create(['role' => 'sales']))->post('/creative-requests', $this->brief())->assertRedirect();
        Notification::assertSentOnDemand(CreativeRequestAssigned::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'creative-alert@example.test' && $n->toMail($notifiable)->actionUrl === CreativeRequest::first()->url());
    }

    public function test_missing_email_configuration_does_not_silently_drop_the_alert(): void
    {
        $this->owner();
        config(['creative.notification_email' => '']);
        $this->actingAs(User::factory()->create(['role' => 'sales']))->post('/creative-requests', $this->brief())->assertSessionHasErrors('notification');
        $this->assertDatabaseCount('creative_requests', 0);
        $this->assertDatabaseCount('lead_tasks', 0);
    }

    public function test_private_reference_files_are_visible_only_to_participants(): void
    {
        $owner = $this->owner();
        $sender = User::factory()->create(['role' => 'sales']);
        $this->actingAs($sender)->post('/creative-requests', $this->brief(['attachments' => [UploadedFile::fake()->image('inspiration.png')]]))->assertRedirect();
        $request = CreativeRequest::firstOrFail();
        $path = $request->attachments[0]['path'];
        Storage::disk('local')->assertExists($path);
        foreach ([$sender, $owner] as $user) {
            $this->actingAs($user)->get("/creative-requests/{$request->id}/attachments/0")->assertOk();
            $this->get("/creative-requests/{$request->id}")->assertOk()->assertDontSee($path, false);
        }
        $this->actingAs(User::factory()->create(['role' => 'education']))->get("/creative-requests/{$request->id}")->assertForbidden();
        $this->get("/creative-requests/{$request->id}/attachments/0")->assertForbidden();
    }

    public function test_invalid_files_and_timeline_leave_no_request_or_task(): void
    {
        $this->owner();
        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->post('/creative-requests', $this->brief(['attachments' => [UploadedFile::fake()->create('payload.html', 2, 'text/html')]]))->assertSessionHasErrors('attachments.0');
        $this->post('/creative-requests', $this->brief(['draft_due' => now()->addDays(9)->toDateString()]))->assertSessionHasErrors('draft_due');
        $this->assertDatabaseCount('lead_tasks', 0);
        $this->assertDatabaseCount('creative_requests', 0);
    }

    public function test_queue_is_scoped_and_uses_live_task_status(): void
    {
        $owner = $this->owner();
        $sender = User::factory()->create(['role' => 'sales']);
        $this->actingAs($sender)->post('/creative-requests', $this->brief());
        $request = CreativeRequest::firstOrFail();
        $request->task->update(['status' => 'in_review']);
        $this->get('/creative-requests')->assertInertia(fn (Assert $p) => $p->component('creative/Index')->has('requests.data', 1)->where('requests.data.0.status', 'in_review'));
        $this->actingAs(User::factory()->create(['role' => 'education']))->get('/creative-requests')->assertInertia(fn (Assert $p) => $p->has('requests.data', 0));
        $this->actingAs($owner)->get('/admin/system-tickets?type=digital')->assertInertia(fn (Assert $p) => $p->component('creative/Index')->has('requests.data', 1));
        $this->get('/admin/system-tickets')->assertInertia(fn (Assert $p) => $p->component('admin/SystemTickets')->has('tickets.data', 0));
    }

    public function test_only_owner_or_admin_updates_status_and_stale_edits_are_rejected(): void
    {
        $owner = $this->owner();
        $sender = User::factory()->create(['role' => 'sales']);
        $this->actingAs($sender)->post('/creative-requests', $this->brief());
        $request = CreativeRequest::firstOrFail();
        $this->post("/creative-requests/{$request->id}/status", ['status' => 'completed', 'expected_status' => 'not_started'])->assertForbidden();
        $this->actingAs($owner)->post("/creative-requests/{$request->id}/status", ['status' => 'in_progress', 'expected_status' => 'not_started'])->assertRedirect();
        $this->post("/creative-requests/{$request->id}/status", ['status' => 'completed', 'expected_status' => 'not_started'])->assertSessionHasErrors('status');
        $this->assertSame('in_progress', $request->task->fresh()->status);
    }

    public function test_dtr_shows_only_the_employees_assigned_creative_work_without_logging_hours(): void
    {
        config(['hr.enabled' => true, 'hr.portal_mode' => true]);
        $owner = $this->owner();
        HrEmployee::create(['user_id' => $owner->id, 'status' => 'active']);
        $sender = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $sender->id, 'status' => 'active']);
        $this->actingAs($sender)->post('/creative-requests', $this->brief());
        $this->actingAs($owner)->get('/portal/hr/dtr')->assertOk()->assertInertia(fn (Assert $p) => $p->has('creativeRequests', 1)->where('creativeRequests.0.title', 'Study campaign'));
        $this->actingAs($sender)->get('/portal/hr/dtr')->assertOk()->assertInertia(fn (Assert $p) => $p->has('creativeRequests', 0));
        $this->assertDatabaseCount('dtr_entries', 0);
    }

    public function test_requester_cannot_bypass_workflow_using_the_shared_task_api(): void
    {
        $this->owner();
        $sender = User::factory()->create(['role' => 'sales']);
        $this->actingAs($sender)->post('/creative-requests', $this->brief());
        $task = CreativeRequest::firstOrFail()->task;
        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'completed'])->assertForbidden();
        $this->patchJson("/api/tasks/{$task->id}", ['assignee_id' => $sender->id])->assertForbidden();
        $this->assertSame('not_started', $task->fresh()->status);
    }

    public function test_long_briefs_still_save_from_the_existing_task_editor(): void
    {
        $owner = $this->owner();
        $overview = trim(str_repeat('Detailed creative context. ', 180));
        $this->actingAs(User::factory()->create(['role' => 'sales']))->post('/creative-requests', $this->brief(['overview' => $overview]));
        $creative = CreativeRequest::firstOrFail();
        $task = $creative->task;
        $this->actingAs($owner)->patch("/api/tasks/{$task->id}", ['title' => $task->title, 'description' => $task->description, 'department' => $task->department, 'category' => $task->category, 'status' => 'in_progress', 'due_at' => $task->due_at->toIso8601String(), 'assignee_ids' => [$owner->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $task->fresh()->status);
        $this->assertSame($overview, $creative->fresh()->brief['overview']);
        $this->get('/creative-requests/task/'.$task->id)->assertRedirect('/creative-requests/'.$creative->id);
    }

    public function test_external_or_inactive_users_cannot_submit_or_read_digital_requests(): void
    {
        foreach ([['role' => 'lead'], ['role' => 'revoked_lead']] as $attributes) {
            $user = User::factory()->create($attributes);
            $this->actingAs($user)->post('/creative-requests', $this->brief())->assertForbidden();
            $this->get('/creative-requests')->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'sales', 'is_active' => false]))
            ->post('/creative-requests', $this->brief())->assertRedirect('/login');
    }
}
