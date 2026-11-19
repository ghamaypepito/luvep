<?php

namespace Tests\Feature\Tickets;

use App\Models\CreativeRequest;
use App\Models\LeadTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreativeAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        config(['creative.assignee_email' => 'creative-owner@example.test']);
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00', 'Asia/Manila')->utc());
    }

    private function creative(User $sender, User $assignee, array $taskData = [], ?Carbon $created = null): CreativeRequest
    {
        $task = LeadTask::create(['created_by' => $sender->id, 'assignee_id' => $assignee->id,
            'title' => 'Synthetic creative brief', 'status' => 'not_started', ...$taskData]);
        $creative = new CreativeRequest(['request_ref' => 'CR-'.fake()->unique()->numerify('########'),
            'lead_task_id' => $task->id, 'brief' => ['types' => ['Digital media']]]);
        $creative->created_at = $created ?? now();
        $creative->save();

        return $creative;
    }

    public function test_totals_cover_every_page_and_owner_workload_includes_coassignments_but_not_deleted_requests(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'email' => 'creative-owner@example.test']);
        $other = User::factory()->create(['role' => 'sales']);
        for ($i = 0; $i < 23; $i++) {
            $this->creative($other, $owner);
        }
        $this->creative($other, $other, ['status' => 'in_progress', 'additional_assignee_ids' => [(string) $owner->id]]);
        $this->creative($other, $other, ['status' => 'in_review']);
        $this->creative($other, $owner, ['status' => 'completed']);
        foreach (['not_started', 'completed'] as $status) {
            $deleted = $this->creative($other, $owner, ['status' => $status]);
            $deleted->task->delete();
            $deleted->delete();
        }
        $this->actingAs($owner)->get('/admin/system-tickets?type=digital')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('requests.data', 20)->where('requests.total', 26)->where('analytics.total', 26)
            ->where('analytics.status_counts', ['not_started' => 23, 'in_progress' => 1, 'in_review' => 1, 'completed' => 1])
            ->where('analytics.owner_counts', ['not_started' => 23, 'in_progress' => 1, 'in_review' => 0, 'completed' => 1])
            ->has('analytics.trends', 8));
        $this->get('/admin/system-tickets?type=digital&view=deleted')->assertInertia(fn (Assert $p) => $p
            ->has('requests.data', 2)->where('analytics', null));
        Notification::assertNothingSent();
        $this->assertDatabaseCount('dtr_entries', 0);
    }

    public function test_staff_metrics_use_the_same_visibility_and_status_filters_as_the_queue(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'email' => 'creative-owner@example.test']);
        $sender = User::factory()->create(['role' => 'sales']);
        $other = User::factory()->create(['role' => 'education']);
        $this->creative($sender, $owner);
        $this->creative($other, $other, ['status' => 'in_review', 'additional_assignee_ids' => [$sender->id]]);
        $this->creative($other, $owner, ['status' => 'completed']);
        $this->actingAs($sender)->get('/creative-requests')->assertInertia(fn (Assert $p) => $p
            ->where('analytics.total', 2)->where('analytics.status_counts.completed', 0)
            ->where('analytics.owner_counts.not_started', 1)->where('analytics.owner_counts.in_review', 0));
        $this->get('/creative-requests?status=in_review')->assertInertia(fn (Assert $p) => $p
            ->has('requests.data', 1)->where('analytics.total', 1)->where('analytics.status_counts.in_review', 1)
            ->where('analytics.status_counts.not_started', 0)->where('analytics.owner_counts.in_review', 0));
        $this->get('/creative-requests?view=deleted')->assertForbidden();
        $this->actingAs($owner)->get('/admin/system-tickets?type=digital&status=completed')->assertInertia(fn (Assert $p) => $p
            ->where('analytics.total', 1)->where('analytics.status_counts.completed', 1)->where('analytics.owner_counts.completed', 1));
    }

    public function test_delivery_chart_uses_recorded_manila_weeks_and_reopening_removes_current_delivery(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'email' => 'creative-owner@example.test']);
        $sender = User::factory()->create(['role' => 'sales']);
        $dated = $this->creative($sender, $owner, ['status' => 'completed', 'completed_at' => Carbon::parse('2026-11-15 16:30:00', 'UTC')], Carbon::parse('2026-11-15 15:30:00', 'UTC'));
        $this->creative($sender, $owner, ['status' => 'completed'], now()->subMonths(3));
        $undated = $this->creative($sender, $owner, ['status' => 'completed']);
        LeadTask::whereKey($undated->lead_task_id)->update(['completed_at' => null]);
        $this->creative($sender, $owner);
        $this->actingAs($owner)->get('/admin/system-tickets?type=digital&status=completed')->assertInertia(fn (Assert $p) => $p
            ->where('analytics.total', 3)->where('analytics.undated_deliveries', 1)
            ->where('analytics.trends.0', ['period' => '2026-09-28', 'created' => 0, 'delivered' => 0])
            ->where('analytics.trends.6', ['period' => '2026-11-09', 'created' => 1, 'delivered' => 0])
            ->where('analytics.trends.7', ['period' => '2026-11-16', 'created' => 1, 'delivered' => 2]));
        $dated->task->update(['status' => 'not_started']);
        $this->get('/admin/system-tickets?type=digital&status=completed')->assertInertia(fn (Assert $p) => $p
            ->where('analytics.total', 2)->where('analytics.status_counts.completed', 2)
            ->where('analytics.trends.7.delivered', 1));
        Notification::assertNothingSent();
    }

    public function test_missing_owner_does_not_hide_visible_requests_or_invent_a_workload(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->creative($admin, $admin);
        $this->actingAs($admin)->get('/admin/system-tickets?type=digital')->assertInertia(fn (Assert $p) => $p
            ->where('analytics.total', 1)->where('owner', null)->where('analytics.owner_counts', null));
    }
}
