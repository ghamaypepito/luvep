<?php

namespace Tests\Feature\Hr;

use App\Models\DtrEntry;
use App\Models\DtrSetting;
use App\Models\HrEmployee;
use App\Models\LeadTask;
use App\Models\LeadTaskComment;
use App\Models\User;
use App\Services\Hr\WorkInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrWorkInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hr.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-04 03:00', 'UTC'));
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(array $attributes = []): HrEmployee
    {
        $user = User::factory()->create(['role' => 'education']);
        $employee = HrEmployee::create(['user_id' => $user->id, 'status' => 'active', ...$attributes]);
        DtrSetting::create(['user_id' => $user->id, 'timezone' => 'Asia/Manila']);

        return $employee;
    }

    private function task(User $user, array $attributes = []): LeadTask
    {
        return LeadTask::create(['lead_id' => \App\Models\Lead::firstOrCreate(['first_name' => 'Synthetic overview', 'last_name' => 'Client'])->id, 'assignee_id' => $user->id, 'created_by' => $user->id, 'title' => 'Synthetic task', 'status' => 'in_progress', ...$attributes]);
    }

    public function test_period_completion_uses_completion_time_and_comments_count_as_updates(): void
    {
        $employee = $this->employee();
        $user = $employee->user;
        $this->task($user, ['status' => 'completed', 'completed' => true, 'completed_at' => '2026-11-02 03:00:00']);
        $this->task($user, ['status' => 'completed', 'completed' => true, 'completed_at' => '2026-11-03 16:30:00']); // Nov 4 in Manila.
        $this->task($user, ['status' => 'completed', 'completed' => true, 'completed_at' => '2026-10-10 03:00:00']); // Updated today, not completed today.
        $open = $this->task($user, ['due_at' => '2026-11-03 02:00:00', 'updated_at' => '2026-10-15 03:00:00']);
        LeadTask::whereKey($open->id)->update(['updated_at' => '2026-10-15 03:00:00']);
        LeadTaskComment::create(['lead_task_id' => $open->id, 'user_id' => $user->id, 'body' => 'PRIVATE COMMENT TEXT']);
        $today = app(WorkInsights::class)->summary($user, 'today');
        $week = app(WorkInsights::class)->summary($user, 'week');
        $this->assertSame(1, $today['summary']['completed']);
        $this->assertSame(2, $week['summary']['completed']);
        $this->assertSame(1, $today['summary']['in_progress']);
        $this->assertSame(1, $today['summary']['overdue']);
        $this->assertSame(4, $today['summary']['updated']);
        $this->assertStringNotContainsString('PRIVATE COMMENT TEXT', json_encode($today));
        $this->assertSame('Asia/Manila', $today['timezone']);
    }

    public function test_head_sees_only_current_team_and_shared_tasks_are_counted_once_overall(): void
    {
        $head = $this->employee();
        $a = $this->employee(['manager_user_id' => $head->user_id]);
        $b = $this->employee(['manager_user_id' => $head->user_id]);
        $outside = $this->employee();
        $shared = $this->task($a->user, ['title' => 'PRIVATE CLIENT TITLE', 'description' => 'PRIVATE DETAILS', 'additional_assignee_ids' => [(string) $b->user_id, $a->user_id]]);
        $this->task($outside->user, ['title' => 'OUTSIDE TEAM']);
        $data = app(WorkInsights::class)->summary($head->user, 'week');
        $this->assertSame('assigned_team', $data['scope']);
        $this->assertSame(1, $data['summary']['in_progress']);
        $this->assertSame(1, collect($data['members']['data'])->firstWhere('id', $a->user_id)['in_progress']);
        $this->assertSame(1, collect($data['members']['data'])->firstWhere('id', $b->user_id)['in_progress']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($data));
        $this->assertStringNotContainsString('OUTSIDE TEAM', json_encode($data));
        $this->assertNull($data['recent_updates'][0]['url']);
        $a->update(['manager_user_id' => null]);
        $b->update(['manager_user_id' => null]);
        $this->assertSame(0, app(WorkInsights::class)->summary($head->user, 'week')['summary']['in_progress']);
    }

    public function test_employee_self_scope_and_admin_preview_do_not_inherit_admin_team_visibility(): void
    {
        $employee = $this->employee();
        $other = $this->employee();
        $this->task($employee->user, ['title' => 'Own task']);
        $this->task($other->user, ['title' => 'HIDDEN OTHER TASK']);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($employee->user)->get('https://hr.luvep.com/?work_period=today')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('workInsights.scope', 'self')->where('workInsights.summary.in_progress', 1));
        $this->actingAs($admin)->withSession(['hr_preview_employee_id' => $employee->id])->get('https://hr.luvep.com/?work_period=week')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('workInsights.scope', 'self')->where('workInsights.summary.in_progress', 1)
                ->has('workInsights.members.data', 1)->where('workInsights.recent_updates.0.url', null))
            ->assertDontSee('HIDDEN OTHER TASK');
    }

    public function test_daily_report_counts_use_work_date_and_do_not_join_task_board_completions(): void
    {
        $employee = $this->employee();
        $record = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => '2026-11-02', 'tasks' => [
            ['task' => 'PRIVATE REPORT CONTENT', 'status' => 'done'], ['task' => 'PRIVATE FOLLOWUP', 'status' => 'in_progress'],
        ]]);
        $record->forceFill(['hr_status' => 'draft'])->save();
        $today = app(WorkInsights::class)->summary($employee->user, 'today');
        $week = app(WorkInsights::class)->summary($employee->user, 'week');
        $this->assertSame(0, $today['daily_reports']['completed_tasks']);
        $this->assertSame(1, $week['daily_reports']['completed_tasks']);
        $this->assertSame(1, $week['daily_reports']['in_progress_tasks']);
        $this->assertSame(0, $week['summary']['completed']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($week));
    }

    public function test_current_work_date_and_late_report_changes_use_their_separate_dates(): void
    {
        $employee = $this->employee();
        $old = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => '2026-10-30', 'tasks' => [['task' => 'PRIVATE OLD WORK', 'status' => 'done']]]);
        $today = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => '2026-11-04', 'tasks' => [['task' => 'PRIVATE TODAY WORK', 'status' => 'done']]]);
        $old->forceFill(['hr_status' => 'draft'])->save();
        $today->forceFill(['hr_status' => 'submitted'])->save();
        \App\Models\HrDtrRevision::create(['dtr_entry_id' => $old->id, 'actor_id' => $employee->user_id, 'version' => 1,
            'action' => 'dtr.draft_saved', 'changes' => ['remarks' => ['before' => 'PRIVATE BEFORE', 'after' => 'PRIVATE AFTER']], 'created_at' => now()]);
        $data = app(WorkInsights::class)->summary($employee->user, 'today');
        $this->assertSame(1, $data['daily_reports']['records']);
        $this->assertSame(1, $data['daily_reports']['completed_tasks']);
        $this->assertSame('2026-10-30', $data['daily_reports']['recent_updates'][0]['work_date']);
        $this->assertSame(1, $data['daily_reports']['recent_updates'][0]['changed_fields']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($data));
    }

    public function test_admin_sees_active_internal_assignments_but_not_archived_or_external_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $active = User::factory()->create(['role' => 'finance']); // HR enrollment is not required for Task Board work.
        $archived = $this->employee();
        $archived->update(['status' => 'archived']);
        $inactive = User::factory()->create(['role' => 'sales', 'is_active' => false]);
        $external = User::factory()->create(['role' => 'lead']);
        foreach ([$active, $archived->user, $inactive, $external] as $user) {
            $this->task($user);
        }
        $data = app(WorkInsights::class)->summary($admin, 'week');
        $this->assertSame('all_staff', $data['scope']);
        $this->assertSame(1, $data['summary']['in_progress']);
        $this->assertSame(2, $data['members']['total']);
        $this->assertSame('/admin/tasks?task='.$data['recent_updates'][0]['id'], $data['recent_updates'][0]['url']);
    }

    public function test_completions_with_no_timestamp_are_explained_and_creator_only_links_do_not_open_the_wrong_queue(): void
    {
        $head = $this->employee();
        $employee = $this->employee(['manager_user_id' => $head->user_id]);
        $task = $this->task($employee->user, ['created_by' => $head->user_id, 'status' => 'completed', 'completed' => true]);
        LeadTask::whereKey($task->id)->update(['completed_at' => null]);
        $data = app(WorkInsights::class)->summary($head->user, 'week');
        $this->assertSame(0, $data['summary']['completed']);
        $this->assertSame(1, $data['undated_completions']);
        $this->assertSame('Synthetic task', $data['recent_updates'][0]['title']);
        $this->assertNull($data['recent_updates'][0]['url']);
    }

    public function test_aggregates_are_not_capped_at_the_task_board_display_limit_and_queries_are_bounded(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = $this->employee();
        $task = $this->task($employee->user);
        $rows = [];
        for ($i = 0; $i < 2100; $i++) {
            $rows[] = ['lead_id' => $task->lead_id, 'created_by' => $employee->user_id,
                'assignee_id' => $employee->user_id, 'title' => 'Synthetic bulk task', 'status' => 'in_progress', 'completed' => false,
                'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            \Illuminate\Support\Facades\DB::table('lead_tasks')->insert($chunk);
        }
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $data = app(WorkInsights::class)->summary($admin, 'week');
        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();
        $this->assertSame(2101, $data['summary']['in_progress']);
        $this->assertCount(8, $data['recent_updates']);
        $this->assertLessThanOrEqual(20, $queries, 'Overview queries must not grow with task rows.');
    }

    public function test_member_pagination_preserves_totals_and_http_filters_are_validated(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        foreach (range(1, 23) as $i) {
            $this->employee();
        }
        $data = app(WorkInsights::class)->summary($admin, 'month', 2);
        $this->assertSame(24, $data['members']['total']);
        $this->assertCount(4, $data['members']['data']);
        $this->assertSame(2, $data['members']['current_page']);
        $this->actingAs($admin)->getJson('https://hr.luvep.com/?work_period=invalid')->assertUnprocessable();
        $this->getJson('https://hr.luvep.com/?team_page=0')->assertUnprocessable();
    }
}
