<?php

namespace Tests\Feature\Hr;

use App\Models\DtrEntry;
use App\Models\DtrSetting;
use App\Models\HrEmployee;
use App\Models\HrShift;
use App\Models\User;
use App\Services\Hr\ClockService;
use App\Services\Hr\DtrService;
use App\Services\Hr\HrAccess;
use App\Services\Hr\RequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hr.enabled' => true]);
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-10-19 10:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(array $user = [], array $schedule = []): HrEmployee
    {
        $u = User::factory()->create(['role' => 'sales', ...$user]);
        $e = HrEmployee::create(['user_id' => $u->id, 'status' => 'active']);
        DtrSetting::create(['user_id' => $u->id, 'timezone' => 'Asia/Manila', 'team' => 'Philippines', 'sched_in' => '08:00', 'sched_out' => '17:00', 'schedule_type' => 'fixed', 'is_complete' => true, ...$schedule]);

        return $e;
    }

    private function record(HrEmployee $e, string $date, string $from, string $to, array $breaks = []): DtrEntry
    {
        $r = DtrEntry::create(['user_id' => $e->user_id, 'work_date' => $date, 'time_in' => '08:00', 'time_out' => '17:00', 'tasks' => []]);
        $r->forceFill(['hr_status' => 'draft', 'time_source' => 'live', 'hr_version' => 0])->save();
        $s = HrShift::create(['hr_employee_id' => $e->id, 'work_date' => $date, 'timezone' => $e->schedule->timezone, 'started_at' => $from, 'ended_at' => $to, 'active_guard' => null]);
        foreach ($breaks as [$a,$b]) {
            $s->breaks()->create(['started_at' => $a, 'ended_at' => $b]);
        }

        return $r;
    }

    private function draft(DtrEntry $r, array $tasks): array
    {
        return ['work_date' => $r->work_date->toDateString(), 'expected_version' => 0, 'work_location' => 'Office', 'tasks' => array_map(fn ($t) => ['task' => 'Recorded work', 'status' => 'done', 'start' => $t[0], 'end' => $t[1]], $tasks)];
    }

    public function test_closed_workday_cannot_clock_in_again_but_next_day_can(): void
    {
        $e = $this->employee();
        $this->record($e, '2026-10-19', '2026-10-19 00:00', '2026-10-19 09:00');
        $this->actingAs($e->user)->postJson('https://hr.luvep.com/clock', ['action' => 'clock_in', 'request_id' => (string) Str::uuid(), 'expected_version' => 0])->assertConflict();
        $this->assertFalse(app(ClockService::class)->state($e)['can_clock_in']);
        Carbon::setTestNow(Carbon::parse('2026-10-20 00:00', 'UTC'));
        $state = app(ClockService::class)->state($e);
        $this->assertTrue($state['can_clock_in']);
        $this->assertSame(0, $state['working_seconds']);
        app(ClockService::class)->transition($e, 'clock_in', (string) Str::uuid(), 0);
        $this->assertSame(2, HrShift::count());
    }

    public function test_archiving_last_access_administrator_requires_another_active_access_administrator(): void
    {
        $last = $this->employee(['role' => 'super_admin']);
        $operator = User::factory()->create(['role' => 'admin']);
        $url = 'https://hr.luvep.com/people/'.$last->id;
        $this->actingAs($operator)->patchJson($url, ['version' => 0, 'status' => 'archived'])->assertConflict();
        $this->assertSame('active', $last->fresh()->status);
        User::factory()->create(['role' => 'admin', 'module_permissions' => ['hr.access.manage']]);
        $this->patchJson($url, ['version' => 0, 'status' => 'archived'])->assertOk();
        $this->assertFalse(app(HrAccess::class)->allows($last->user->fresh(), 'access.manage'));
    }

    public function test_minute_task_boundaries_align_to_real_clock_and_break_seconds(): void
    {
        $e = $this->employee();
        $r = $this->record($e, '2026-10-19', '2026-10-19 00:00:30', '2026-10-19 09:00:10', [['2026-10-19 04:00:20', '2026-10-19 04:30:40']]);
        $saved = app(DtrService::class)->saveDraft($e, $this->draft($r, [['08:00', '12:00'], ['12:30', '17:00']]));
        $this->assertSame(30560, app(DtrService::class)->row($saved)['task_seconds']);
    }

    public function test_overnight_employee_can_finish_previous_date_draft_on_clock_out_day(): void
    {
        $e = $this->employee();
        $r = $this->record($e, '2026-10-19', '2026-10-19 14:00', '2026-10-19 22:00');
        Carbon::setTestNow(Carbon::parse('2026-10-19 22:01', 'UTC'));
        $saved = app(DtrService::class)->saveDraft($e, $this->draft($r, [['22:00', '23:00']]));
        $this->assertTrue(app(DtrService::class)->row($saved)['can_edit']);
        $this->assertSame('submitted', app(DtrService::class)->submit($e->user, $saved, $saved->hr_version)->hr_status);
    }

    public function test_overnight_minute_boundaries_preserve_actual_clock_seconds(): void
    {
        $e = $this->employee();
        $r = $this->record($e, '2026-10-19', '2026-10-19 14:00:30', '2026-10-19 22:00:10');
        Carbon::setTestNow(Carbon::parse('2026-10-19 22:01', 'UTC'));
        $saved = app(DtrService::class)->saveDraft($e, $this->draft($r, [['22:00', '06:00']]));
        $this->assertSame(28780, app(DtrService::class)->row($saved)['task_seconds']);
    }

    public function test_overnight_task_rollover_uses_local_date_across_dst(): void
    {
        $e = $this->employee([], ['timezone' => 'Pacific/Auckland', 'team' => 'New Zealand']);
        $r = $this->record($e, '2026-04-04', '2026-04-04 10:00', '2026-04-04 15:00');
        $r->forceFill(['hr_status' => 'returned'])->save();
        $saved = app(DtrService::class)->saveDraft($e, $this->draft($r, [['23:00', '03:00']]));
        $this->assertSame(18000, app(DtrService::class)->row($saved)['task_seconds']);
    }

    public function test_preview_reports_cannot_inherit_subjects_team_scope(): void
    {
        $subject = $this->employee(['module_permissions' => ['hr.reports']]);
        $other = $this->employee(['name' => 'OTHER PRIVATE EMPLOYEE']);
        $this->record($subject, '2026-10-19', '2026-10-19 00:00', '2026-10-19 09:00');
        $this->record($other, '2026-10-19', '2026-10-19 00:00', '2026-10-19 09:00');
        $a = User::factory()->create(['role' => 'admin']);
        $url = 'https://hr.luvep.com/reports?type=attendance&from=2026-10-01&to=2026-10-31';
        $this->actingAs($a)->withSession(['hr_preview_employee_id' => $subject->id])->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->has('rows', 1)->has('employees', 1))->assertDontSee('OTHER PRIVATE EMPLOYEE');
        $this->get($url.'&employee_id='.$other->id)->assertNotFound();
        $this->get('https://hr.luvep.com/reports?type=policy_acknowledgements&from=2026-10-01&to=2026-10-31')->assertNotFound();
    }

    public function test_preview_blocks_legacy_team_attendance_reads(): void
    {
        $e = $this->employee();
        $a = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($a)->withSession(['hr_preview_employee_id' => $e->id])->get('https://luvep.com/admin/dtr/reports')->assertForbidden();
        $this->get('https://luvep.com/dtr/report?date=2026-10-19&user='.$a->id)->assertForbidden();
    }

    public function test_archiving_explicitly_granted_employee_revokes_all_hr_access(): void
    {
        $e = $this->employee(['module_permissions' => ['hr.reports', 'hr.documents.medical', 'hr.cases.manage', 'hr.audit']]);
        $e->update(['status' => 'archived']);
        foreach (['reports', 'documents.medical', 'cases.manage', 'audit'] as $g) {
            $this->assertFalse(app(HrAccess::class)->allows($e->user, $g));
        }
        $this->actingAs($e->user)->get('https://hr.luvep.com/reports')->assertForbidden();
        $e->update(['status' => 'active']);
        $e->schedule->update(['archived_at' => now()]);
        $this->assertFalse(app(HrAccess::class)->allows($e->user, 'reports'));
    }

    public function test_profile_save_preserves_existing_day_specific_schedule(): void
    {
        $e = $this->employee();
        $weekly = collect(DtrSetting::WEEK_DAYS)->mapWithKeys(fn ($d) => [$d => ['on' => $d !== 'sun', 'in' => '08:00', 'out' => $d === 'sat' ? '12:00' : '17:00']])->all();
        $e->schedule->update(['weekly_schedule' => $weekly]);
        $a = User::factory()->create(['role' => 'admin']);
        $this->actingAs($a)->patchJson('https://hr.luvep.com/people/'.$e->id, ['version' => 0, 'status' => 'active', 'department' => 'Updated', 'manager_user_id' => $a->id, 'reviewer_user_id' => $a->id, 'timezone' => 'Asia/Manila', 'team' => 'Philippines', 'schedule_type' => 'fixed', 'sched_in' => '08:00', 'sched_out' => '17:00', 'working_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat']])->assertOk();
        $this->assertSame('12:00', $e->schedule->fresh()->weekly_schedule['sat']['out']);
    }

    public function test_reassigned_case_rejects_reply_using_stale_assignment(): void
    {
        $e = $this->employee();
        $owner = $this->employee(['module_permissions' => ['hr.cases.assigned']]);
        $next = $this->employee(['module_permissions' => ['hr.cases.assigned']]);
        $a = User::factory()->create(['role' => 'super_admin']);
        $s = app(RequestService::class);
        $case = $s->open($e->user, 'general', 'Private request', 'Original body', (string) Str::uuid());
        $case = $s->assign($a, $case, $owner->user, 0);
        $stale = $case->fresh();
        $s->assign($a, $case, $next->user, $case->version);
        try {
            $s->reply($owner->user, $stale, 'No longer authorized', true);
            $this->fail('Former assignee wrote a note');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $x) {
            $this->assertSame(404, $x->getStatusCode());
        }
        $this->assertSame(1, $case->messages()->count());
    }
}
