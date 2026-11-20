<?php

namespace Tests\Feature\Hr;

use App\Models\{DtrEntry, DtrSetting, HrDtrAcknowledgement, HrEmployee, HrShift, HrWeeklySignoff, User};
use App\Services\Hr\{DtrCalendar, DtrHistory, DtrService, ReportService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrDtrCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hr.enabled' => true]);
        $this->withoutVite();
        Carbon::setTestNow('2026-11-19 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(array $attributes = []): HrEmployee
    {
        return HrEmployee::create(['user_id' => User::factory()->create(['role' => 'education'])->id, 'status' => 'active', ...$attributes]);
    }

    private function entry(HrEmployee $employee, string $date = '2026-11-17', string $status = 'submitted'): DtrEntry
    {
        $entry = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => $date, 'time_in' => '08:00', 'time_out' => '17:00', 'remarks' => 'PRIVATE WORK NOTE', 'tasks' => []]);
        $entry->forceFill(['hr_status' => $status, 'hr_version' => 4])->save();

        return $entry;
    }

    public function test_super_admin_sees_every_enrolled_employee_including_own_record_but_cannot_self_review(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $own = HrEmployee::create(['user_id' => $admin->id, 'status' => 'active']);
        $ownEntry = $this->entry($own);
        foreach (['active', 'candidate', 'archived'] as $status) {
            $this->entry($this->employee(['status' => $status]));
        }
        $this->actingAs($admin)->get('https://hr.luvep.com/reports?from=2026-11-01&to=2026-11-30')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('employees', 4)->has('calendarRecords', 4));
        $this->get('https://hr.luvep.com/dtr/changes')->assertOk()->assertInertia(fn (Assert $page) => $page->has('records.data', 4));
        $this->getJson('https://hr.luvep.com/dtr/'.$ownEntry->id.'/history?details=1')->assertOk()->assertJsonPath('entry.can_review', false);
        $this->postJson('https://hr.luvep.com/dtr/'.$ownEntry->id.'/review', ['version' => 4, 'decision' => 'reviewed'])->assertForbidden();
    }

    public function test_calendar_combines_report_and_acknowledgements_without_exposing_other_teams_or_eager_notes(): void
    {
        $head = $this->employee();
        $employee = $this->employee(['manager_user_id' => $head->user_id]);
        $entry = $this->entry($employee);
        $other = $this->employee();
        $hidden = $this->entry($other);
        $history = app(DtrHistory::class);
        for ($version = 1; $version <= 12; $version++) {
            HrDtrAcknowledgement::create(['dtr_entry_id' => $entry->id, 'head_user_id' => $head->user_id, 'report_version' => $version,
                'status' => $version === 12 ? 'pending' : 'superseded', 'reason' => 'Report amended', 'snapshot' => $history->reportSnapshot($entry), 'changes' => [], 'submitted_at' => now()]);
        }
        $this->actingAs($head->user)->get('https://hr.luvep.com/dtr/changes')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.data.0.id', $entry->id)->where('records.data.0.pending_acknowledgements', 1))
            ->assertDontSee('PRIVATE WORK NOTE');
        $url = 'https://hr.luvep.com/dtr/'.$entry->id.'/history?details=1';
        $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('entry.remarks', 'PRIVATE WORK NOTE')
            ->assertJsonPath('entry.can_review', false)->assertJsonPath('entry.can_print', false)->assertJsonCount(10, 'acknowledgements.data')->assertJsonPath('acknowledgements.total', 12)
            ->assertJsonPath('acknowledgements.data.0.can_decide', true);
        $this->getJson($url.'&ack_page=2')->assertJsonCount(2, 'acknowledgements.data');
        $this->getJson('https://hr.luvep.com/dtr/'.$hidden->id.'/history?details=1')->assertNotFound();
        $employee->update(['manager_user_id' => null]);
        $this->getJson($url)->assertNotFound();
    }

    public function test_report_only_permission_does_not_grant_private_dtr_details_and_preview_is_read_only(): void
    {
        $employee = $this->employee();
        $entry = $this->entry($employee);
        $reporter = User::factory()->create(['role' => 'sales', 'module_permissions' => ['hr.reports']]);
        $this->actingAs($reporter)->get('https://hr.luvep.com/reports?from=2026-11-01&to=2026-11-30')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('calendarRecords', 1)->where('calendarRecords.0.can_view_details', false))->assertDontSee('PRIVATE WORK NOTE');
        $this->getJson('https://hr.luvep.com/dtr/'.$entry->id.'/history?details=1')->assertNotFound();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->withSession(['hr_preview_employee_id' => $employee->id])
            ->get('https://hr.luvep.com/reports?from=2026-11-01&to=2026-11-30')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('calendarRecords.0.can_view_details', false));
        $this->getJson('https://hr.luvep.com/dtr/'.$entry->id.'/history?details=1')->assertForbidden();
    }

    public function test_calendar_honours_range_employee_status_and_keeps_older_reviews_discoverable(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $employee = $this->employee();
        $old = $this->entry($employee, '2026-09-30');
        $current = $this->entry($employee);
        $this->entry($employee, '2026-11-18', 'reviewed');
        $this->actingAs($admin)->get('https://hr.luvep.com/dtr/review')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('entries.0.id', $current->id)->where('oldestPending', '2026-09-30'));
        $this->get('https://hr.luvep.com/dtr/review?from=2026-09-01&to=2026-09-30')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('entries.0.id', $old->id));
        $this->get('https://hr.luvep.com/reports?type=approved_hours&from=2026-11-01&to=2026-11-30')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('calendarRecords', 1)->where('calendarRecords.0.hr_status', 'reviewed'));
        $this->getJson('https://hr.luvep.com/dtr/changes?from=2025-01-01&to=2026-11-30')->assertUnprocessable();
        $this->getJson('https://hr.luvep.com/dtr/changes?employee_id=999999')->assertNotFound();
        $this->getJson('https://hr.luvep.com/dtr/review?from=invalid')->assertUnprocessable();
    }

    public function test_all_matching_records_reach_weekly_totals_beyond_500_with_bounded_queries_and_unchanged_csv(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $employees = collect([$this->employee(), $this->employee()]);
        foreach ($employees as $employee) {
            $values = [];
            for ($day = 0; $day < 260; $day++) {
                $values[] = ['user_id' => $employee->user_id, 'work_date' => Carbon::parse('2026-01-01')->addDays($day)->toDateString(), 'time_in' => '08:00', 'time_out' => '17:00', 'hr_status' => 'reviewed', 'hr_version' => 1, 'time_source' => 'manual'];
            }
            DtrEntry::insert($values);
        }
        $filters = ['from' => '2026-01-01', 'to' => '2026-12-31'];
        DB::enableQueryLog(); DB::flushQueryLog();
        $records = app(DtrCalendar::class)->records($admin, $employees->each->load('user'), $filters);
        $this->assertCount(520, $records);
        $this->assertSame(520 * 8 * 3600, $records->sum('working_seconds'));
        $this->assertLessThan(30, count(DB::getQueryLog()), 'Calendar totals must not issue one query per record.');
        DB::disableQueryLog();
        $csv = app(ReportService::class)->rows($admin, 'attendance', $filters)->first();
        $this->assertSame(['employee', 'employee_number', 'department', 'work_date', 'status', 'time_source', 'working_seconds', 'break_seconds'], array_keys($csv));
    }

    public function test_old_acknowledgements_and_cross_month_weekly_declarations_remain_discoverable(): void
    {
        $head = $this->employee();
        $employee = $this->employee(['manager_user_id' => $head->user_id, 'reviewer_user_id' => $head->user_id, 'department' => 'Education']);
        $entry = $this->entry($employee, '2026-10-29', 'reviewed');
        HrDtrAcknowledgement::create(['dtr_entry_id' => $entry->id, 'head_user_id' => $head->user_id, 'report_version' => 4,
            'status' => 'pending', 'reason' => 'Updated today for a previous month', 'snapshot' => app(DtrHistory::class)->reportSnapshot($entry), 'changes' => [], 'submitted_at' => now()]);
        $this->actingAs($head->user)->get('https://hr.luvep.com/dtr/changes')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('records.data', 0)->where('oldestPending', '2026-10-29'));
        $this->get('https://hr.luvep.com/dtr/changes?from=2026-10-01&to=2026-10-31')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.data.0.pending_acknowledgements', 1));
        HrWeeklySignoff::create(['hr_employee_id' => $employee->id, 'week_start' => '2026-10-26', 'status' => 'submitted', 'version' => 3, 'revisions' => [], 'signed_at' => now(), 'signed_by' => $employee->user_id]);
        $this->get('https://hr.luvep.com/dtr/review?from=2026-11-01&to=2026-11-30')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('weeks', 1)->where('oldestPending', '2026-10-26')->where('weeks.0.department', 'Education'));
        $this->get('https://hr.luvep.com/dtr/review?department=Sales')->assertOk()->assertInertia(fn (Assert $page) => $page->has('weeks', 0)->where('oldestPending', null));
        $this->get('https://hr.luvep.com/dtr/changes?department=Sales')->assertOk()->assertInertia(fn (Assert $page) => $page->has('records.data', 0)->where('oldestPending', null));
    }

    public function test_batch_totals_match_individual_live_break_and_overnight_manual_records(): void
    {
        $employee = $this->employee();
        $live = $this->entry($employee);
        $live->forceFill(['time_source' => 'live'])->save();
        $shift = HrShift::create(['hr_employee_id' => $employee->id, 'work_date' => '2026-11-17', 'timezone' => 'UTC', 'started_at' => '2026-11-17 08:00', 'ended_at' => '2026-11-17 17:00']);
        foreach ([['12:00', '12:30'], ['12:15', '13:00']] as [$start, $end]) $shift->breaks()->create(['started_at' => '2026-11-17 '.$start, 'ended_at' => '2026-11-17 '.$end, 'active_guard' => null]);
        $manual = $this->entry($employee, '2026-11-18');
        $manual->update(['time_in' => '22:00', 'time_out' => '06:00']);
        DtrSetting::create(['user_id' => $employee->user_id, 'break_hours' => 0.5, 'break_after' => 4]);
        $service = app(DtrService::class);
        $totals = $service->attendanceTotals(collect([$live, $manual]));
        foreach ([$live, $manual] as $entry) {
            $row = $service->row($entry);
            $this->assertSame($row['working_seconds'], $totals[$entry->id]['working_seconds']);
            $this->assertSame($row['break_seconds'], $totals[$entry->id]['break_seconds']);
        }
        $this->assertSame(3600, $totals[$live->id]['break_seconds']);
        $this->assertSame(27000, $totals[$manual->id]['working_seconds']);
    }
}
