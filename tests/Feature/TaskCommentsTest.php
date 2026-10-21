<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadTask;
use App\Models\LeadTaskComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCommentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_participants_can_add_and_read_comments(): void
    {
        $creator = User::factory()->create(['role' => 'sales']);
        $assignee = User::factory()->create(['role' => 'education']);
        $additional = User::factory()->create(['role' => 'immigration']);
        $admin = User::factory()->create(['role' => 'admin']);
        $task = LeadTask::create(['lead_id' => Lead::create(['first_name' => 'Test', 'last_name' => 'Client'])->id,
            'title' => 'Confidential follow-up', 'created_by' => $creator->id,
            'assignee_id' => $assignee->id, 'additional_assignee_ids' => [$additional->id],
            'department' => 'sales',
        ]);

        foreach ([$creator, $assignee, $additional, $admin] as $user) {
            $this->actingAs($user)->postJson("/api/tasks/{$task->id}/comments", ['body' => 'Progress update'])
                ->assertCreated()->assertJsonPath('comment.author.id', $user->id);
            $this->getJson("/api/tasks/{$task->id}/comments")->assertOk()
                ->assertJsonPath('comments.0.body', 'Progress update');
            $this->assertDatabaseHas('lead_task_comments', ['lead_task_id' => $task->id, 'user_id' => $user->id]);
        }
    }

    public function test_unrelated_staff_including_same_department_cannot_read_or_write_comments(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $task = LeadTask::create(['lead_id' => Lead::create(['first_name' => 'Test', 'last_name' => 'Client'])->id, 'title' => 'Private task', 'created_by' => $owner->id, 'assignee_id' => $owner->id, 'department' => 'sales']);
        LeadTaskComment::create(['lead_task_id' => $task->id, 'user_id' => $owner->id, 'body' => 'Private comment']);

        foreach (['sales', 'education'] as $role) {
            $stranger = User::factory()->create(['role' => $role]);
            $this->actingAs($stranger)->getJson("/api/tasks/{$task->id}/comments")
                ->assertForbidden()->assertDontSee('Private comment');
            $this->postJson("/api/tasks/{$task->id}/comments", ['body' => 'Intrusion'])
                ->assertForbidden();
        }
        $this->assertDatabaseCount('lead_task_comments', 1);
    }

    public function test_guests_external_accounts_and_inactive_participants_cannot_access_comments(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $task = LeadTask::create(['lead_id' => Lead::create(['first_name' => 'Test', 'last_name' => 'Client'])->id, 'title' => 'Private task', 'created_by' => $owner->id, 'assignee_id' => $owner->id]);
        $url = "/api/tasks/{$task->id}/comments";
        $this->getJson($url)->assertUnauthorized();
        $this->postJson($url, ['body' => 'Guest'])->assertUnauthorized();

        foreach (['lead', 'revoked_lead', 'agent', 'sub_agent'] as $role) {
            $external = User::factory()->create(['role' => $role, 'portal_access' => ['sales']]);
            $task->update(['assignee_id' => $external->id]);
            $this->actingAs($external)->getJson($url)->assertForbidden();
            $this->actingAs($external)->postJson($url, ['body' => 'External'])->assertForbidden();
        }
        $owner->update(['is_active' => false]);
        $this->actingAs($owner)->getJson($url)->assertForbidden();
        $this->actingAs($owner)->postJson($url, ['body' => 'Inactive'])->assertForbidden();
        $this->assertDatabaseCount('lead_task_comments', 0);
    }

    public function test_missing_task_returns_not_found_and_invalid_comment_is_not_saved(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $this->actingAs($owner)->getJson('/api/tasks/99999/comments')->assertNotFound();
        $this->postJson('/api/tasks/99999/comments', ['body' => 'No task'])->assertNotFound();
        $task = LeadTask::create(['lead_id' => Lead::create(['first_name' => 'Test', 'last_name' => 'Client'])->id, 'title' => 'Private task', 'created_by' => $owner->id, 'assignee_id' => $owner->id]);
        $this->postJson("/api/tasks/{$task->id}/comments", ['body' => ''])->assertUnprocessable();
        $this->assertDatabaseCount('lead_task_comments', 0);
    }
}
