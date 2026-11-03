<?php

namespace Tests\Feature\Notifications;

use App\Models\Lead;
use App\Models\LeadTask;
use App\Models\User;
use App\Notifications\TaskAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinanceTaskNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_notifications_open_linked_and_unlinked_tasks_in_finance(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Client']);

        foreach ([null, $lead->id] as $leadId) {
            $task = LeadTask::create([
                'title' => 'Synthetic Finance task', 'created_by' => $admin->id,
                'assignee_id' => $finance->id, 'lead_id' => $leadId,
            ]);
            $notification = new TaskAssigned($task, 'Test Admin');
            $url = '/portal/finance/tasks?task='.$task->id;
            $this->assertSame($url, $notification->toArray($finance)['link']);
            $this->assertSame($leadId ? '/admin/leads/'.$leadId : '/admin/tasks', $notification->toArray($admin)['link']);

            $this->actingAs($finance)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('portal/finance/Tasks')
                ->where('selected_task.id', $task->id));
        }

        $this->actingAs($finance)->get('/admin/tasks')->assertForbidden();
    }

    public function test_historical_notification_links_are_corrected_in_bell_and_inbox(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $notification = DatabaseNotification::create([
            'id' => (string) Str::uuid(), 'type' => TaskAssigned::class,
            'notifiable_type' => User::class, 'notifiable_id' => $finance->id,
            'data' => ['task_id' => 42, 'link' => '/admin/leads/123'],
        ]);

        $url = '/portal/finance/tasks?task=42';
        $this->actingAs($finance)->getJson('/api/notifications/recent')->assertOk()
            ->assertJsonPath('notifications.0.url', $url);
        $this->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('portal/finance/Notifications')
            ->where('notifications.data.0.url', $url));
        $this->assertSame('/admin/leads/123', $notification->fresh()->data['link']);
    }

    public function test_additional_assignees_see_the_task_but_unrelated_finance_staff_cannot_open_it(): void
    {
        $primary = User::factory()->create(['role' => 'sales']);
        $finance = User::factory()->create(['role' => 'finance']);
        $unrelated = User::factory()->create(['role' => 'finance']);
        $task = LeadTask::create([
            'title' => 'Shared synthetic task', 'created_by' => $primary->id,
            'assignee_id' => $primary->id, 'additional_assignee_ids' => [$finance->id],
        ]);

        $url = '/portal/finance/tasks?task='.$task->id;
        $this->actingAs($finance)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('selected_task.id', $task->id)
            ->has('all_tasks', 1)->where('all_tasks.0.id', $task->id));
        $this->getJson('/api/tasks/'.$task->id.'/comments')->assertOk();

        $this->actingAs($unrelated)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('selected_task', null)->has('all_tasks', 0));
        $this->getJson('/api/tasks/'.$task->id.'/comments')->assertForbidden();
        $this->patchJson('/api/tasks/'.$task->id, ['title' => 'Unauthorised change'])->assertForbidden();
        $this->get('/portal/finance/tasks?task=999999')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('selected_task', null));
    }
}
