<?php

namespace Tests\Feature\Dtr;

use App\Models\ActivityLog;
use App\Models\DtrEntry;
use App\Models\DtrSetting;
use App\Models\HrAuditEvent;
use App\Models\HrEmployee;
use App\Models\HrShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LegacyCandidateClockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hr.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-21 00:17:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function candidate(): HrEmployee
    {
        $user = User::factory()->create(['role' => 'immigration_manager', 'module_permissions' => []]);
        DtrSetting::create(['user_id' => $user->id, 'timezone' => 'Asia/Manila', 'sched_in' => '05:00', 'sched_out' => '14:00', 'is_complete' => true]);

        return HrEmployee::create(['user_id' => $user->id, 'status' => 'candidate']);
    }

    public function test_draft_hr_enrollment_does_not_block_yesterdays_legacy_clock_out(): void
    {
        $employee = $this->candidate();
        $tasks = [['task' => 'Existing work', 'status' => 'todo', 'pending_done' => false]];
        $entry = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'time_in' => '05:00', 'tasks' => $tasks, 'remarks' => 'Keep remarks']);
        $other = DtrEntry::create(['user_id' => User::factory()->create()->id, 'work_date' => '2026-11-20', 'time_in' => '05:00']);

        $this->actingAs($employee->user)->from('/portal/immigration/dtr')->post('/dtr/time-out')->assertRedirect('/portal/immigration/dtr');

        $this->assertSame('08:17', $entry->fresh()->time_out);
        $this->assertSame('05:00', $entry->time_in);
        $this->assertSame($tasks, $entry->tasks);
        $this->assertSame('Keep remarks', $entry->remarks);
        $this->assertNull($other->fresh()->time_out);
        $this->assertSame('candidate', $employee->fresh()->status);
        $this->assertSame([], $employee->user->fresh()->module_permissions);
        $this->assertSame(0, HrShift::count());
    }

    public function test_candidate_can_clock_in_and_save_legacy_tasks_without_hr_activation(): void
    {
        $employee = $this->candidate();
        $this->actingAs($employee->user)->post('/dtr/time-in')->assertRedirect();
        $this->post('/dtr/entry', ['work_date' => '2026-11-21', 'time_in' => '08:17', 'tasks' => [['task' => 'Today', 'status' => 'todo']]])->assertRedirect();
        $entry = DtrEntry::where('user_id', $employee->user_id)->firstOrFail();
        $this->assertSame('08:17', $entry->time_in);
        $this->assertSame('Today', $entry->tasks[0]['task']);
        $this->assertSame('legacy', $entry->time_source);
    }

    public function test_authorized_legacy_admin_can_correct_candidate_historical_record(): void
    {
        $employee = $this->candidate();
        $entry = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'time_in' => '05:00']);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->post('/admin/dtr/entry', ['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'time_in' => '05:00', 'time_out' => '14:00', 'tasks' => [], 'remarks' => 'Confirmed actual finish; ticket255'])->assertRedirect();
        $this->assertSame('14:00', $entry->fresh()->time_out);
        $this->assertSame('candidate', $employee->fresh()->status);
    }

    public function test_candidate_with_live_hr_history_cannot_fall_back_to_legacy_writes(): void
    {
        $employee = $this->candidate();
        $entry = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => '2026-11-21', 'time_in' => '05:00']);
        $entry->forceFill(['time_source' => 'live'])->save();
        $this->actingAs($employee->user)->post('/dtr/time-out')->assertForbidden();
        $this->postJson('/dtr/entry', ['user_id' => 999999, 'work_date' => '2026-11-21', 'time_in' => '00:00'])->assertStatus(409);
        $this->assertNull($entry->fresh()->time_out);
        $this->assertSame('05:00', $entry->time_in);
    }

    public function test_candidate_with_archived_schedule_remains_blocked(): void
    {
        $employee = $this->candidate();
        $employee->schedule->update(['archived_at' => now()]);
        $this->actingAs($employee->user)->post('/dtr/time-out')->assertForbidden();
        $this->postJson('/dtr/entry', ['work_date' => '2026-11-21', 'time_in' => '00:00'])->assertStatus(409);
        $this->assertSame(0, DtrEntry::count());
    }

    public function test_repeated_legacy_clock_out_does_not_invent_a_record_or_change_the_first_time(): void
    {
        $employee = $this->candidate();
        $entry = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'time_in' => '05:00']);
        $this->actingAs($employee->user)->post('/dtr/time-out')->assertRedirect();
        Carbon::setTestNow(Carbon::parse('2026-11-21 00:19:00', 'UTC'));
        $this->post('/dtr/time-out')->assertRedirect();
        $this->assertSame(1, DtrEntry::where('user_id', $employee->user_id)->count());
        $this->assertSame('08:17', $entry->fresh()->time_out);
    }

    public function test_candidate_with_hr_shifts_still_requires_hr_even_without_live_dtr_row(): void
    {
        $employee = $this->candidate();
        HrShift::create(['hr_employee_id' => $employee->id, 'work_date' => '2026-11-20', 'timezone' => 'Asia/Manila', 'started_at' => '2026-11-19 21:00:00', 'ended_at' => '2026-11-20 06:00:00']);
        $this->actingAs($employee->user)->post('/dtr/time-in')->assertForbidden();
        $this->post('/dtr/time-out')->assertForbidden();
        $this->postJson('/dtr/entry', ['work_date' => '2026-11-21', 'time_in' => '00:00'])->assertStatus(409);
        $this->assertSame(0, DtrEntry::count());
    }

    public function test_time_only_admin_correction_preserves_exact_tasks_and_records_actor_without_report_content(): void
    {
        $employee = $this->candidate();
        $tasks = [['task' => 'PRIVATE WORK DETAIL', 'status' => 'done', 'completed_at' => null, 'reference' => 'PRIVATE_REFERENCE'], ['task' => 'Planned work', 'status' => 'todo', 'pending_done' => false]];
        $entry = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'time_in' => '05:00', 'tasks' => $tasks, 'remarks' => 'Keep remarks']);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $payload = ['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'time_in' => '05:00', 'time_out' => '14:00', 'tasks' => array_map(fn ($task) => ['task' => $task['task'], 'pending' => '', 'pending_done' => false], $tasks), 'remarks' => 'Keep remarks'];
        $this->actingAs($admin)->post('/admin/dtr/entry', $payload)->assertRedirect();
        $entry->refresh();
        $this->assertSame($tasks, $entry->tasks);
        $this->assertSame('Keep remarks', $entry->remarks);
        $this->assertSame('05:00', $entry->time_in);
        $this->assertSame('14:00', $entry->time_out);
        $this->assertSame(1, DtrEntry::count());
        $log = ActivityLog::where('action', 'dtr.time_corrected')->sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(['time_out'], $log->properties['fields']);
        $this->assertSame($entry->id, $log->properties['dtr_entry_id']);
        $this->assertStringNotContainsString('PRIVATE', $log->toJson());
        $this->post('/admin/dtr/entry', $payload)->assertRedirect();
        $this->assertSame(1, ActivityLog::where('action', 'dtr.time_corrected')->count());
    }

    public function test_time_correction_rolls_back_if_its_audit_cannot_be_recorded(): void
    {
        $employee = $this->candidate();
        $entry = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'time_in' => '05:00']);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $beforeCount = ActivityLog::count();
        ActivityLog::creating(fn () => throw new \RuntimeException('Synthetic audit failure'));
        $this->actingAs($admin)->postJson('/admin/dtr/entry', ['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'time_in' => '05:00', 'time_out' => '14:00', 'tasks' => [], 'remarks' => null])->assertStatus(500);
        $this->assertNull($entry->fresh()->time_out);
        $this->assertSame($beforeCount, ActivityLog::count());
        $this->assertSame(0, ActivityLog::where('action', 'dtr.time_corrected')->count());
    }

    public function test_rejected_late_hr_clock_in_keeps_its_automatic_closure_committed(): void
    {
        $employee = $this->candidate();
        $employee->update(['status' => 'active']);
        $this->actingAs($employee->user)->post('/dtr/time-in')->assertRedirect();
        $shift = HrShift::sole();
        Carbon::setTestNow(Carbon::parse('2026-11-21 11:17:00', 'UTC'));
        $this->post('/dtr/time-in')->assertStatus(409);
        $this->assertSame('2026-11-21 10:17:00', $shift->fresh()->ended_at?->format('Y-m-d H:i:s'));
        $this->assertSame(1, HrAuditEvent::where('action', 'clock.auto_clock_out')->count());
        $this->assertSame(2, $employee->fresh()->clock_version);
        $this->assertSame(1, HrShift::count());
        $entry = DtrEntry::where('user_id', $employee->user_id)->sole();
        $this->assertSame('18:17', $entry->time_out);
    }

    public function test_overnight_target_closes_only_the_displayed_entry_even_on_retry(): void
    {
        $employee = $this->candidate();
        $yesterday = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'time_in' => '05:00']);
        $today = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => '2026-11-21', 'time_in' => '06:00']);
        $this->actingAs($employee->user)->post('/dtr/time-out', ['entry_id' => $yesterday->id])->assertRedirect();
        $this->assertSame('08:17', $yesterday->fresh()->time_out);
        $this->assertNull($today->fresh()->time_out);
        Carbon::setTestNow(Carbon::parse('2026-11-21 00:19:00', 'UTC'));
        $this->post('/dtr/time-out', ['entry_id' => $yesterday->id])->assertRedirect();
        $this->assertSame('08:17', $yesterday->fresh()->time_out);
        $this->assertNull($today->fresh()->time_out);
        $this->assertSame(2, DtrEntry::count());
    }

    public function test_ambiguous_untargeted_legacy_clock_out_requires_refresh(): void
    {
        $employee = $this->candidate();
        foreach (['2026-11-20', '2026-11-21'] as $date) {
            DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => $date, 'time_in' => '05:00']);
        }
        $this->actingAs($employee->user)->post('/dtr/time-out')->assertStatus(409);
        $this->assertSame(2, DtrEntry::whereNull('time_out')->count());
    }

    public function test_targeted_legacy_request_cannot_close_an_hr_shift_after_activation(): void
    {
        $employee = $this->candidate();
        $legacy = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'time_in' => '05:00']);
        $employee->update(['status' => 'active']);
        $this->actingAs($employee->user)->post('/dtr/time-in')->assertRedirect();
        $shift = HrShift::sole();
        $this->post('/dtr/time-out', ['entry_id' => $legacy->id])->assertForbidden();
        $this->assertNull($shift->fresh()->ended_at);
        $this->assertNull($legacy->fresh()->time_out);
        $this->assertSame(1, $employee->fresh()->clock_version);
    }

    public function test_named_target_requires_own_eligible_legacy_entry(): void
    {
        $employee = $this->candidate();
        $foreign = DtrEntry::create(['user_id' => User::factory()->create()->id, 'work_date' => '2026-11-20', 'time_in' => '05:00']);
        $this->actingAs($employee->user)->postJson('/dtr/time-out', ['entry_id' => $foreign->id])->assertNotFound();
        foreach ([null, '', 0, 'invalid'] as $target) {
            $this->postJson('/dtr/time-out', ['entry_id' => $target])->assertUnprocessable();
        }
        $this->assertNull($foreign->fresh()->time_out);
    }

    public function test_partial_time_correction_preserves_blank_rows_metadata_and_fresh_remarks(): void
    {
        $employee = $this->candidate();
        $tasks = [['task' => '', 'pending' => '', 'tid' => 'blank-preserved'], ['task' => 'Fresh saved task', 'status' => 'todo', 'tid' => 'stable-id', 'kind' => 'side', 'reference' => 'PRIVATE_REFERENCE']];
        $entry = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'time_in' => '05:00', 'tasks' => $tasks, 'remarks' => 'Fresh saved remarks']);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->post('/admin/dtr/entry', ['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'time_out' => '14:00'])->assertRedirect();
        $entry->refresh();
        $this->assertSame('14:00', $entry->time_out);
        $this->assertSame('05:00', $entry->time_in);
        $this->assertSame($tasks, $entry->tasks);
        $this->assertSame('Fresh saved remarks', $entry->remarks);
        $this->assertSame(['time_out'], ActivityLog::where('action', 'dtr.time_corrected')->sole()->properties['fields']);
        $this->post('/admin/dtr/entry', ['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'remarks' => null])->assertRedirect();
        $this->assertNull($entry->fresh()->remarks);
        $this->assertSame($tasks, $entry->tasks);
        $this->assertSame('14:00', $entry->time_out);
    }

    public function test_editor_expected_record_cannot_overwrite_a_replacement_or_recreate_a_deleted_entry(): void
    {
        $employee = $this->candidate();
        $entry = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'time_in' => '05:00']);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $payload = ['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'expected_entry_id' => $entry->id, 'time_out' => '14:00'];
        $this->actingAs($admin)->postJson('/admin/dtr/entry', [...$payload, 'expected_entry_id' => null])->assertStatus(409);
        $this->assertNull($entry->fresh()->time_out);
        $entry->delete();
        $this->postJson('/admin/dtr/entry', $payload)->assertStatus(409);
        $this->assertSame(0, DtrEntry::count());
    }

    public function test_report_editor_receives_the_existing_record_identity_and_can_correct_it(): void
    {
        $this->withoutVite();
        $employee = $this->candidate();
        $entry = DtrEntry::create(['user_id' => $employee->user_id, 'work_date' => '2026-11-20', 'time_in' => '05:00', 'remarks' => 'Keep current report']);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $response = $this->actingAs($admin)->get('/admin/dtr/reports?date=2026-11-20')->assertOk();
        $row = collect($response->inertiaProps()['roster'])->firstWhere('user_id', $employee->user_id);
        $this->assertSame($entry->id, $row['entry_id']);
        $this->post('/admin/dtr/entry', ['user_id' => $employee->user_id, 'work_date' => '2026-11-20',
            'expected_entry_id' => $row['entry_id'], 'time_out' => '14:00'])->assertRedirect();
        $this->assertSame('14:00', $entry->fresh()->time_out);
        $this->assertSame('Keep current report', $entry->remarks);
    }
}
