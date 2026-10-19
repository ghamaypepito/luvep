<?php

namespace Tests\Feature\Hr;

use App\Models\DtrEntry;
use App\Models\DtrSetting;
use App\Models\HrDtrReview;
use App\Models\HrEmployee;
use App\Models\HrWeeklySignoff;
use App\Models\User;
use App\Services\Hr\ClockService;
use App\Services\Hr\DtrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HrDtrTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fixture(string $date = '2026-10-19'): array
    {
        config(['hr.enabled' => true]);
        $user = User::factory()->create(['role' => 'sales']);
        $reviewer = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $reviewer->id, 'status' => 'active']);
        $employee = HrEmployee::create(['user_id' => $user->id, 'status' => 'active', 'reviewer_user_id' => $reviewer->id]);
        DtrSetting::create(['user_id' => $user->id, 'timezone' => 'Asia/Manila', 'team' => 'Philippines', 'sched_in' => '08:00', 'sched_out' => '17:00', 'schedule_type' => 'fixed', 'is_complete' => true, 'break_hours' => 1, 'break_after' => 6]);
        Carbon::setTestNow(Carbon::parse($date.' 00:00', 'UTC'));
        app(ClockService::class)->transition($employee, 'clock_in', (string) Str::uuid(), 0);
        Carbon::setTestNow(Carbon::parse($date.' 04:00', 'UTC'));
        app(ClockService::class)->transition($employee, 'break_out', (string) Str::uuid(), 1);
        Carbon::setTestNow(Carbon::parse($date.' 04:30', 'UTC'));
        app(ClockService::class)->transition($employee, 'break_in', (string) Str::uuid(), 2);
        Carbon::setTestNow(Carbon::parse($date.' 08:00', 'UTC'));
        app(ClockService::class)->transition($employee, 'clock_out', (string) Str::uuid(), 3);

        return [$employee, $reviewer, DtrEntry::where('user_id', $user->id)->first()];
    }

    private function data(DtrEntry $entry): array
    {
        return ['work_date' => $entry->work_date->toDateString(), 'expected_version' => $entry->hr_version, 'work_location' => 'Office', 'tasks' => [['task' => 'Client coordination', 'status' => 'done', 'category' => 'client', 'start' => '08:00', 'end' => '09:00', 'reference' => 'Project A']], 'meetings' => 1, 'people_assisted' => 2, 'blockers' => 'None', 'tomorrow' => 'Follow up'];
    }

    public function test_live_record_uses_actual_breaks_and_structured_task_hours(): void
    {
        [$e,,$entry] = $this->fixture();
        $saved = app(DtrService::class)->saveDraft($e, $this->data($entry));
        $row = app(DtrService::class)->row($saved);
        $this->assertSame(27000, $row['working_seconds']);
        $this->assertSame(1800, $row['break_seconds']);
        $this->assertSame(3600, $row['task_seconds']);
        $this->assertSame('Office', $saved->work_location);
    }

    public function test_submission_locks_edits_and_only_assigned_ops_can_review(): void
    {
        [$e,$reviewer,$entry] = $this->fixture();
        $service = app(DtrService::class);
        $entry = $service->saveDraft($e, $this->data($entry));
        $entry = $service->submit($e->user, $entry, $entry->hr_version);
        $this->assertSame('submitted', $entry->hr_status);
        $this->assertNotNull($entry->submitted_at);
        $this->actingAs($e->user)->postJson('/dtr/entry', ['work_date' => '2026-10-19', 'time_in' => '01:00', 'tasks' => []])->assertStatus(409);
        $other = User::factory()->create(['role' => 'sales']);
        try {
            $service->review($other, $entry, 'reviewed', '', $entry->hr_version);
            $this->fail('Unassigned review succeeded');
        } catch (HttpException $ex) {
            $this->assertSame(403, $ex->getStatusCode());
        }
        $reviewed = $service->review($reviewer, $entry, 'reviewed', 'Checked', $entry->hr_version);
        $this->assertSame('reviewed', $reviewed->hr_status);
        $this->assertSame(1, HrDtrReview::count());
    }

    public function test_returned_past_day_can_be_corrected_and_resubmitted(): void
    {
        [$e,$reviewer,$entry] = $this->fixture();
        $service = app(DtrService::class);
        $entry = $service->saveDraft($e, $this->data($entry));
        $entry = $service->submit($e->user, $entry, $entry->hr_version);
        $entry = $service->review($reviewer, $entry, 'returned', 'Please complete the reference.', $entry->hr_version);
        Carbon::setTestNow(Carbon::parse('2026-10-20 08:00', 'UTC'));
        $entry = $service->saveDraft($e, [...$this->data($entry), 'blockers' => 'Updated']);
        $this->assertSame('Updated', $entry->blockers);
        $entry = $service->submit($e->user, $entry, $entry->hr_version);
        $this->assertSame('submitted', $entry->hr_status);
    }

    public function test_task_intervals_cannot_overlap_a_break_or_each_other(): void
    {
        [$e,,$entry] = $this->fixture();
        $data = $this->data($entry);
        foreach ([['start' => '12:00', 'end' => '12:30'], ['start' => '07:00', 'end' => '09:00']] as $bad) {
            $data['tasks'][0] = [...$data['tasks'][0], ...$bad];
            try {
                app(DtrService::class)->saveDraft($e, $data);
                $this->fail('Invalid interval saved');
            } catch (ValidationException $ex) {
                $this->assertArrayHasKey('tasks', $ex->errors());
            }
        }
        $data = $this->data($entry);
        $data['tasks'][] = $data['tasks'][0];
        try {
            app(DtrService::class)->saveDraft($e, $data);
            $this->fail('Overlapping tasks saved');
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('tasks', $ex->errors());
        }
    }

    public function test_legacy_autosave_cannot_replace_live_time_or_delete_record(): void
    {
        [$e,,$entry] = $this->fixture();
        $this->actingAs($e->user)->postJson('/dtr/entry', ['work_date' => '2026-10-19', 'time_in' => '01:00', 'time_out' => '02:00', 'tasks' => []])->assertStatus(409);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->deleteJson('/admin/dtr/entry', ['user_id' => $e->user_id, 'work_date' => '2026-10-19'])->assertStatus(409);
        $this->assertSame('08:00', $entry->fresh()->time_in);
    }

    public function test_correction_preserves_originals_and_invalidates_weekly_signature(): void
    {
        [$e,,$entry] = $this->fixture();
        $service = app(DtrService::class);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $entry = $service->saveDraft($e, $this->data($entry));
        $entry = $service->submit($e->user, $entry, $entry->hr_version);
        $weekly = HrWeeklySignoff::create(['hr_employee_id' => $e->id, 'week_start' => '2026-10-19', 'revisions' => [$entry->id => $entry->hr_version], 'status' => 'submitted', 'signed_by' => $e->user_id, 'signed_at' => now()]);
        $shift = \App\Models\HrShift::first();
        $entry = $service->correct($admin, $entry, [['shift_id' => $shift->id, 'started_at' => '2026-10-19T00:00:00Z', 'ended_at' => '2026-10-19T07:00:00Z', 'breaks' => [['started_at' => '2026-10-19T04:00:00Z', 'ended_at' => '2026-10-19T04:30:00Z']]]], 'Confirmed early finish', $entry->hr_version);
        $this->assertSame('returned', $entry->hr_status);
        $this->assertSame('stale', $weekly->fresh()->status);
        $this->assertSame(23400, $service->row($entry)['working_seconds']);
        $this->assertStringContainsString('08:00:00', json_encode(HrDtrReview::where('decision', 'corrected')->first()->before_state));
    }

    public function test_partial_week_cannot_be_signed_and_legacy_rows_remain_historical(): void
    {
        [$e,,$entry] = $this->fixture();
        try {
            app(DtrService::class)->signWeek($e->user, $e, '2026-10-19');
            $this->fail('Partial week was signed');
        } catch (ValidationException $ex) {
            $this->assertArrayHasKey('week', $ex->errors());
        }
        $legacy = DtrEntry::create(['user_id' => $e->user_id, 'work_date' => '2026-10-17', 'time_in' => '08:00', 'time_out' => '17:00', 'tasks' => [['task' => 'Legacy task']]]);
        $row = app(DtrService::class)->row($legacy->fresh());
        $this->assertSame('legacy', $row['hr_status']);
        $this->assertNull($legacy->fresh()->submitted_at);
        $this->assertSame(28800, $row['working_seconds']);
    }

    public function test_legacy_user_id_cannot_bypass_protection_of_own_live_record(): void
    {
        [$e,,$entry] = $this->fixture();
        $this->actingAs($e->user)->postJson('/dtr/entry', ['user_id' => 999999, 'work_date' => '2026-10-19', 'time_in' => '01:00', 'tasks' => []])->assertStatus(409);
        $this->assertSame('08:00', $entry->fresh()->time_in);
    }

    public function test_submitting_through_http_requires_the_employee_declaration(): void
    {
        [$e,,$entry] = $this->fixture();
        $service = app(DtrService::class);
        $entry = $service->saveDraft($e, $this->data($entry));
        $this->actingAs($e->user)->postJson('https://hr.luvep.com/dtr/'.$entry->id.'/submit', ['version' => $entry->hr_version])->assertUnprocessable();
        $this->postJson('https://hr.luvep.com/dtr/'.$entry->id.'/submit', ['version' => $entry->hr_version, 'declaration' => true])->assertOk()->assertJsonPath('entry.hr_status', 'submitted');
    }

    public function test_complete_week_can_be_signed_and_reviewed_by_assigned_ops_only(): void
    {
        [$e,$reviewer,$entry] = $this->fixture();
        foreach (range(19, 24) as $day) {
            $record = DtrEntry::where('user_id', $e->user_id)->whereDate('work_date', '2026-10-'.$day)->first() ?? new DtrEntry(['user_id' => $e->user_id, 'work_date' => '2026-10-'.$day]);
            $record->forceFill(['hr_status' => 'reviewed', 'hr_version' => 2, 'time_in' => '08:00', 'time_out' => '17:00'])->save();
        }
        Carbon::setTestNow(Carbon::parse('2026-10-24 10:00', 'UTC'));
        $service = app(DtrService::class);
        $week = $service->signWeek($e->user, $e, '2026-10-19');
        $this->assertSame('submitted', $week->status);
        $this->assertCount(6, $week->revisions);
        $this->assertSame($week->id, $service->signWeek($e->user, $e, '2026-10-19')->id);
        $week = $service->reviewWeek($reviewer, $week, 'reviewed', 'Checked', $week->version);
        $this->assertSame('reviewed', $week->status);
        $this->assertSame($reviewer->id, $week->reviewed_by);
    }

    public function test_existing_admin_attendance_review_access_is_preserved(): void
    {
        [$e,,$entry] = $this->fixture();
        $service = app(DtrService::class);
        $entry = $service->saveDraft($e, $this->data($entry));
        $entry = $service->submit($e->user, $entry, $entry->hr_version);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertSame('reviewed', $service->review($admin, $entry, 'reviewed', '', $entry->hr_version)->hr_status);
    }

    public function test_printable_reports_scope_employee_and_assigned_reviewer(): void
    {
        [$e,$reviewer,$entry] = $this->fixture();
        app(DtrService::class)->saveDraft($e, $this->data($entry));
        $other = User::factory()->create(['role' => 'sales']);
        $otherEmployee = HrEmployee::create(['user_id' => $other->id, 'status' => 'active']);
        DtrEntry::create(['user_id' => $other->id, 'work_date' => '2026-10-19', 'remarks' => 'PRIVATE OTHER RECORD']);
        $url = 'https://hr.luvep.com/dtr/report?date=2026-10-19';
        $this->actingAs($e->user)->get($url)->assertOk()->assertSee('Client coordination')->assertSee('7h 30m')->assertDontSee('PRIVATE OTHER RECORD');
        $this->get($url.'&employee='.$otherEmployee->id)->assertNotFound();
        $this->actingAs($reviewer)->get($url.'&employee='.$e->id)->assertOk()->assertSee('Client coordination');
        $this->actingAs($other)->get($url.'&employee='.$e->id)->assertNotFound();
        $this->actingAs($e->user)->get('https://hr.luvep.com/dtr/report?week=2026-10-19')->assertOk()->assertSee('Weekly time record')->assertDontSee('PRIVATE OTHER RECORD');
    }

    public function test_employee_cannot_correct_clock_events_or_review_self_and_stale_edit_fails(): void
    {
        [$e,,$entry] = $this->fixture();
        $service = app(DtrService::class);
        $data = $this->data($entry);
        $service->saveDraft($e, $data);
        $this->actingAs($e->user)->postJson('https://hr.luvep.com/dtr', $data)->assertStatus(409);
        $this->postJson('https://hr.luvep.com/dtr/'.$entry->id.'/correct', ['version' => 1, 'reason' => 'Changed', 'intervals' => [['shift_id' => 1]]])->assertForbidden();
        $this->postJson('https://hr.luvep.com/dtr/'.$entry->id.'/review', ['version' => 1, 'decision' => 'reviewed'])->assertForbidden();
    }

    public function test_correction_editor_requires_explicit_grant_and_closed_live_record(): void
    {
        [$e,$reviewer,$entry] = $this->fixture();
        $url = 'https://hr.luvep.com/dtr/corrections?employee_id='.$e->id.'&date=2026-10-19';
        $this->withoutVite();
        $this->actingAs($reviewer)->get($url)->assertForbidden();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->get($url)->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p->has('record.shifts', 1)->where('record.employee_name', $e->user->name));
        $this->withSession(['hr_preview_employee_id' => $e->id])->get($url)->assertForbidden();
    }
}
