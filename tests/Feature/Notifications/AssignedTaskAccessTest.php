<?php

namespace Tests\Feature\Notifications;

use App\Models\Lead;
use App\Models\LeadTask;
use App\Models\User;
use App\Notifications\TaskAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssignedTaskAccessTest extends TestCase
{
    use RefreshDatabase;

    private function task(User $assignee, array $attributes = []): LeadTask
    {
        return LeadTask::create(array_merge([
            'lead_id' => Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Task Client'])->id,
            'title' => 'Synthetic assigned task', 'assignee_id' => $assignee->id,
            'created_by' => $assignee->id, 'department' => 'finance',
            'due_at' => now()->addDay(),
        ], $attributes));
    }

    public function test_new_assignment_notifications_use_the_recipients_task_board(): void
    {
        foreach (['finance' => '/portal/finance/tasks', 'education' => '/portal/education/tasks',
            'sales' => '/portal/sales/tasks', 'immigration_manager' => '/portal/immigration/tasks',
            'immigration_adviser' => '/portal/immigration-adviser/tasks', 'accommodation' => '/portal/accommodation/tasks',
            'admin' => '/admin/tasks', 'super_admin' => '/admin/tasks'] as $role => $path) {
            $user = User::factory()->create(['role' => $role]);
            $task = $this->task($user);
            $data = (new TaskAssigned($task, 'Synthetic creator'))->toArray($user);
            $this->assertSame($path.'?task='.$task->id, $data['link']);
            $this->actingAs($user)->get($data['link'])->assertOk();
        }
    }

    public function test_recipient_without_a_task_board_uses_their_canonical_home(): void
    {
        $user = User::factory()->create(['role' => 'sub_agent']);
        $task = $this->task($user);
        $data = (new TaskAssigned($task, 'Synthetic creator'))->toArray($user);
        $this->assertSame('/portal/sub-agent/dashboard', $data['link']);
    }

    public function test_legacy_notifications_are_corrected_on_read_without_changing_saved_rows(): void
    {
        $user = User::factory()->create(['role' => 'finance']);
        $task = $this->task($user);
        foreach (['/admin/tasks', '/admin/leads/1006'] as $legacy) {
            $n = DatabaseNotification::create([
                'id' => (string) Str::uuid(), 'type' => TaskAssigned::class,
                'notifiable_type' => User::class, 'notifiable_id' => $user->id,
                'data' => ['title' => 'Assigned task', 'task_id' => $task->id, 'link' => $legacy],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $before = $n->fresh()->getAttributes();
            $this->actingAs($user)->getJson('/api/notifications/recent')->assertOk()
                ->assertJsonPath('notifications.0.url', '/portal/finance/tasks?task='.$task->id);
            $this->assertSame($before, $n->fresh()->getAttributes());
            $n->delete();
        }
        $this->actingAs($user)->get('/admin/tasks')->assertForbidden();
        $this->get('/notifications')->assertOk()->assertInertia(fn ($page) => $page->component('portal/finance/Notifications'));
    }

    public function test_finance_mine_includes_secondary_assignments_and_excludes_unrelated_tasks(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $other = User::factory()->create(['role' => 'education']);
        $primary = $this->task($finance);
        $secondary = $this->task($other, ['additional_assignee_ids' => [$finance->id], 'department' => 'education']);
        $legacySecondary = $this->task($other, ['additional_assignee_ids' => [(string) $finance->id], 'department' => 'education']);
        $unrelated = $this->task($other, ['department' => 'education']);
        foreach (['mine', 'all', 'invalid'] as $scope) {
            $this->actingAs($finance)->get('/portal/finance/tasks?scope='.$scope)->assertOk()
                ->assertInertia(fn ($page) => $page->where('scope', 'mine')
                    ->where('all_tasks', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all()
                        === collect([$primary->id, $secondary->id, $legacySecondary->id])->sort()->values()->all()));
        }
        $this->get('/portal/finance/tasks?scope=department')->assertOk()->assertInertia(fn ($page) => $page
            ->where('all_tasks', fn ($rows) => collect($rows)->pluck('id')->all() === [$primary->id]));
        $this->assertSame(0, $unrelated->comments()->count());
    }

    public function test_secondary_assignees_can_follow_task_links_in_every_department(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        foreach (['sales', 'education', 'immigration', 'immigration_manager', 'immigration_adviser', 'accommodation', 'finance'] as $role) {
            $staff = User::factory()->create(['role' => $role]);
            $assigned = $this->task($owner, ['department' => 'admin', 'additional_assignee_ids' => [$staff->id]]);
            $unrelated = $this->task($owner, ['department' => 'admin']);
            $link = (new TaskAssigned($assigned, 'Synthetic creator'))->toArray($staff)['link'];
            foreach ([$link, $link.'&scope=all'] as $url) {
                $this->actingAs($staff)->get($url)->assertOk()->assertInertia(fn ($page) => $page
                    ->where('scope', 'mine')->where('all_tasks', fn ($rows) => collect($rows)->pluck('id')->all() === [$assigned->id]));
            }
            $this->getJson('/api/tasks/'.$assigned->id.'/comments')->assertOk();
            $this->getJson('/api/tasks/'.$unrelated->id.'/comments')->assertForbidden();
        }
    }

    public function test_task_focus_cannot_add_an_unrelated_task_to_the_finance_response(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $other = User::factory()->create(['role' => 'education']);
        $unrelated = $this->task($other, ['department' => 'education']);
        $this->actingAs($finance)->get('/portal/finance/tasks?task='.$unrelated->id)->assertOk()
            ->assertInertia(fn ($page) => $page->has('all_tasks', 0));
        $this->getJson('/api/tasks/'.$unrelated->id.'/comments')->assertForbidden();
    }
}
