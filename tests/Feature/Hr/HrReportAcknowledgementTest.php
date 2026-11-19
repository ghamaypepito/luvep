<?php

namespace Tests\Feature\Hr;

use App\Models\DtrEntry;
use App\Models\DtrSetting;
use App\Models\HrDtrAcknowledgement;
use App\Models\HrDtrRevision;
use App\Models\HrEmployee;
use App\Models\HrShift;
use App\Models\HrWeeklySignoff;
use App\Models\User;
use App\Services\Hr\DtrHistory;
use App\Services\Hr\DtrService;
use App\Services\Hr\HrAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrReportAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fixture(string $status = 'reviewed'): array
    {
        config(['hr.enabled' => true]);
        $this->withoutVite();
        Carbon::setTestNow('2026-11-18 08:00:00');
        $owner = User::factory()->create(['role' => 'sales']);
        $head = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $head->id, 'status' => 'active']);
        $employee = HrEmployee::create(['user_id' => $owner->id, 'status' => 'active', 'manager_user_id' => $head->id]);
        DtrSetting::create(['user_id' => $owner->id, 'timezone' => 'Asia/Manila']);
        $entry = DtrEntry::create(['user_id' => $owner->id, 'work_date' => '2026-11-17', 'time_in' => '08:00', 'time_out' => '17:00', 'tasks' => []]);
        $entry->forceFill(['hr_status' => $status, 'hr_version' => 4, 'work_location' => 'Office'])->save();

        return [$owner, $head, $employee, $entry->fresh()];
    }

    private function save(User $owner, DtrEntry $entry, array $data = [])
    {
        return $this->actingAs($owner)->postJson('https://hr.luvep.com/dtr/'.$entry->id.'/report', [...[
            'expected_version' => $entry->hr_version, 'work_date' => $entry->work_date->toDateString(), 'work_location' => 'Remote', 'tasks' => [],
        ], ...$data]);
    }

    private function submit(User $owner, DtrEntry $entry, string $reason = 'Correct work location')
    {
        return $this->actingAs($owner)->postJson('https://hr.luvep.com/dtr/'.$entry->id.'/acknowledgements', ['version' => $entry->hr_version, 'reason' => $reason]);
    }

    public function test_content_save_preserves_review_and_clock_invalidates_week_and_audits(): void
    {
        [$owner,, $employee, $entry] = $this->fixture();
        $week = HrWeeklySignoff::create(['hr_employee_id' => $employee->id, 'week_start' => '2026-11-16', 'revisions' => [$entry->id => 4], 'status' => 'reviewed', 'signed_by' => $owner->id, 'signed_at' => now()]);
        $this->save($owner, $entry)->assertOk()->assertJsonPath('entry.hr_status', 'reviewed')->assertJsonPath('entry.hr_version', 5);
        $entry->refresh();
        $this->assertSame('08:00', $entry->time_in);
        $this->assertSame('17:00', $entry->time_out);
        $this->assertSame('stale', $week->fresh()->status);
        $this->assertSame('dtr.report_saved', HrDtrRevision::sole()->action);
        $this->assertSame(['before' => 'Office', 'after' => 'Remote'], HrDtrRevision::sole()->changes['work_location']);
        $this->save($owner, $entry, ['time_in' => '01:00'])->assertUnprocessable();
        $this->assertSame('08:00', $entry->fresh()->time_in);
    }

    public function test_stale_save_and_foreign_owner_are_rejected_and_date_cannot_move(): void
    {
        [$owner,,, $entry] = $this->fixture();
        $this->save($owner, $entry)->assertOk();
        $this->save($owner, $entry, ['remarks' => 'stale'])->assertConflict();
        $other = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $other->id, 'status' => 'active']);
        $this->save($other, $entry->fresh())->assertNotFound();
        $this->save($owner, $entry->fresh(), ['work_date' => '2026-11-16'])->assertUnprocessable();
        $entry->refresh()->forceFill(['work_date' => '2026-11-19'])->save();
        $this->save($owner, $entry)->assertUnprocessable();
    }

    public function test_fixed_acknowledgement_and_baseline_advance_only_after_head_acknowledges(): void
    {
        [$owner, $head,, $entry] = $this->fixture();
        $this->save($owner, $entry)->assertOk();
        $entry->refresh();
        $this->submit($owner, $entry)->assertOk()->assertJsonPath('acknowledgement.status', 'pending')->assertJsonPath('acknowledgement.report_version', 5);
        $ack = HrDtrAcknowledgement::sole();
        $this->assertSame('Office', $ack->changes['work_location']['before']);
        $this->actingAs($head)->postJson('https://hr.luvep.com/dtr/acknowledgements/'.$ack->id.'/decision', ['version' => $ack->version, 'decision' => 'acknowledged'])->assertOk()->assertJsonPath('acknowledgement.status', 'acknowledged');
        $this->save($owner, $entry, ['work_location' => 'Client site'])->assertOk();
        $this->submit($owner, $entry->fresh())->assertOk()->assertJsonPath('acknowledgement.changes.work_location.before', 'Remote')->assertJsonPath('acknowledgement.changes.work_location.after', 'Client site');
        $this->assertSame('Remote', $ack->fresh()->snapshot['work_location']);
    }

    public function test_new_content_supersedes_pending_and_old_decision_cannot_apply(): void
    {
        [$owner, $head,, $entry] = $this->fixture('submitted');
        $this->save($owner, $entry)->assertOk();
        $this->submit($owner, $entry->fresh())->assertOk();
        $ack = HrDtrAcknowledgement::sole();
        $this->save($owner, $entry->fresh(), ['remarks' => 'More detail'])->assertOk();
        $this->assertSame('superseded', $ack->fresh()->status);
        $this->actingAs($head)->postJson('https://hr.luvep.com/dtr/acknowledgements/'.$ack->id.'/decision', ['version' => 1, 'decision' => 'acknowledged'])->assertConflict();
        $this->assertSame('submitted', $entry->fresh()->hr_status);
    }

    public function test_ack_requires_saved_changes_reason_current_version_and_active_other_head(): void
    {
        [$owner, $head, $employee, $entry] = $this->fixture();
        $this->submit($owner, $entry)->assertUnprocessable();
        $this->save($owner, $entry)->assertOk();
        $this->submit($owner, $entry)->assertConflict();
        $entry->refresh();
        $this->submit($owner, $entry, '   ')->assertUnprocessable();
        $employee->update(['manager_user_id' => $owner->id]);
        $this->submit($owner, $entry)->assertUnprocessable();
        $employee->update(['manager_user_id' => $head->id]);
        $head->update(['is_active' => false]);
        $this->submit($owner, $entry)->assertUnprocessable();
        $head->update(['is_active' => true]);
        $this->submit($owner, $entry)->assertOk();
        $this->submit($owner, $entry)->assertConflict();
        $this->assertSame(1, HrDtrAcknowledgement::count());
    }

    public function test_only_assigned_head_decides_and_clarification_never_reverts_content(): void
    {
        [$owner, $head,, $entry] = $this->fixture();
        $this->save($owner, $entry)->assertOk();
        $this->submit($owner, $entry->fresh())->assertOk();
        $ack = HrDtrAcknowledgement::sole();
        $data = ['version' => $ack->version, 'decision' => 'clarification'];
        $this->actingAs($owner)->postJson('https://hr.luvep.com/dtr/acknowledgements/'.$ack->id.'/decision', $data)->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('https://hr.luvep.com/dtr/acknowledgements/'.$ack->id.'/decision', $data)->assertForbidden();
        $this->actingAs($head)->postJson('https://hr.luvep.com/dtr/acknowledgements/'.$ack->id.'/decision', $data)->assertUnprocessable();
        $this->postJson('https://hr.luvep.com/dtr/acknowledgements/'.$ack->id.'/decision', [...$data, 'comment' => 'Which office?'])->assertOk();
        $this->postJson('https://hr.luvep.com/dtr/acknowledgements/'.$ack->id.'/decision', [...$data, 'comment' => 'Duplicate'])->assertConflict();
        $this->assertSame('Remote', $entry->fresh()->work_location);
        $this->assertSame('reviewed', $entry->hr_status);
    }

    public function test_mine_and_team_inboxes_are_scoped_and_preview_cannot_mutate(): void
    {
        [$owner, $head,, $entry] = $this->fixture();
        $this->save($owner, $entry)->assertOk();
        $this->submit($owner, $entry->fresh())->assertOk();
        $this->actingAs($owner)->get('https://hr.luvep.com/dtr/changes?scope=mine')->assertInertia(fn (Assert $page) => $page->component('hr/DtrChanges')->where('mode', 'mine')->has('entries', 1)->where('departmentHead.id', $head->id)->has('acknowledgements', 1));
        $this->actingAs($head)->get('https://hr.luvep.com/dtr/changes')->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.data.0.pending_acknowledgements', 1));
        $this->getJson('https://hr.luvep.com/dtr/'.$entry->id.'/history?details=1')->assertOk()->assertJsonPath('acknowledgements.data.0.can_decide', true);
        $unrelated = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $unrelated->id, 'status' => 'active']);
        $this->actingAs($unrelated)->get('https://hr.luvep.com/dtr/changes')->assertForbidden();
        $this->get('https://hr.luvep.com/dtr/'.$entry->id.'/history')->assertNotFound();
        $this->actingAs($owner)->withSession(['hr_preview_employee_id' => 999])->postJson('https://hr.luvep.com/dtr/'.$entry->id.'/report', ['expected_version' => 5, 'work_date' => '2026-11-17', 'tasks' => []])->assertForbidden();
    }

    public function test_all_existing_past_statuses_allow_content_edits_and_keep_status(): void
    {
        [$owner,,, $entry] = $this->fixture();
        foreach (['legacy', 'draft', 'returned', 'submitted', 'reviewed'] as $status) {
            $entry->refresh()->forceFill(['hr_status' => $status])->save();
            $this->save($owner, $entry, ['remarks' => $status])->assertOk()->assertJsonPath('entry.hr_status', $status);
        }
    }

    public function test_noop_keeps_pending_version_and_reverted_changes_cannot_be_submitted(): void
    {
        [$owner,,, $entry] = $this->fixture();
        $this->save($owner, $entry)->assertOk();
        $this->submit($owner, $entry->fresh())->assertOk();
        $this->save($owner, $entry->fresh())->assertOk()->assertJsonPath('entry.hr_version', 5);
        $this->assertSame('pending', HrDtrAcknowledgement::sole()->status);
        $this->assertSame(1, HrDtrRevision::count());
        $this->save($owner, $entry->fresh(), ['remarks' => 'New detail'])->assertOk();
        $this->save($owner, $entry->fresh(), ['work_location' => 'Office', 'remarks' => null])->assertOk();
        $this->assertSame('Office', app(DtrHistory::class)->reportBaseline($entry->fresh())['work_location']);
        $this->submit($owner, $entry->fresh())->assertUnprocessable();
    }

    public function test_reassigned_heads_lose_access_and_admin_can_observe_but_cannot_decide(): void
    {
        [$owner, $head, $employee, $entry] = $this->fixture();
        $this->save($owner, $entry)->assertOk();
        $this->submit($owner, $entry->fresh())->assertOk();
        $ack = HrDtrAcknowledgement::sole();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('https://hr.luvep.com/dtr/changes')->assertOk()->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.data.0.pending_acknowledgements', 1));
        $this->getJson('https://hr.luvep.com/dtr/'.$entry->id.'/history?details=1')->assertOk()->assertJsonPath('acknowledgements.data.0.can_decide', false);
        $employee->update(['manager_user_id' => $admin->id]);
        $decision = ['version' => 1, 'decision' => 'acknowledged'];
        $this->actingAs($head)->postJson('https://hr.luvep.com/dtr/acknowledgements/'.$ack->id.'/decision', $decision)->assertForbidden();
        $this->get('https://hr.luvep.com/dtr/changes')->assertForbidden();
        $this->actingAs($admin)->postJson('https://hr.luvep.com/dtr/acknowledgements/'.$ack->id.'/decision', $decision)->assertForbidden();
    }

    public function test_report_history_and_supersession_roll_back_when_audit_fails(): void
    {
        [$owner,,, $entry] = $this->fixture();
        $this->save($owner, $entry)->assertOk();
        $this->submit($owner, $entry->fresh())->assertOk();
        $this->mock(HrAudit::class, fn ($mock) => $mock->shouldReceive('record')->andThrow(new \RuntimeException('Audit unavailable')));
        $this->save($owner, $entry->fresh(), ['remarks' => 'Must roll back'])->assertStatus(500);
        $this->assertNull($entry->fresh()->remarks);
        $this->assertSame(5, $entry->fresh()->hr_version);
        $this->assertSame(1, HrDtrRevision::count());
        $this->assertSame('pending', HrDtrAcknowledgement::sole()->status);
    }

    public function test_submitted_snapshot_and_reason_cannot_be_rewritten(): void
    {
        [$owner,,, $entry] = $this->fixture();
        $this->save($owner, $entry)->assertOk();
        $this->submit($owner, $entry->fresh())->assertOk();
        $this->expectException(\LogicException::class);
        HrDtrAcknowledgement::sole()->update(['reason' => 'Tampered']);
    }

    public function test_unchanged_historical_tasks_keep_original_data_and_task_edits_are_read_only(): void
    {
        [$owner,,, $entry] = $this->fixture('legacy');
        $original = [['description' => 'Historical call', 'start' => '08:00', 'end' => '09:00', 'seconds' => 3600, 'completed_at' => '2026-11-17T01:00:00Z', 'status' => 'carry', 'pending' => true, 'pending_done' => false, 'kind' => 'subtask', 'parent' => 'parent1', 'tid' => 'task2']];
        $entry->update(['tasks' => $original]);
        $normalized = [['task' => 'Historical call', 'start' => '08:00', 'end' => '09:00', 'category' => 'other', 'reference' => '', 'status' => 'carry']];
        $this->save($owner, $entry, ['tasks' => $normalized, 'remarks' => 'Updated note'])->assertOk();
        $this->assertSame($original, $entry->fresh()->tasks);
        foreach (['task' => 'Changed description', 'status' => 'done', 'start' => '07:00'] as $field => $value) {
            $changed = $normalized;
            $changed[0][$field] = $value;
            $this->save($owner, $entry->fresh(), ['tasks' => $changed])->assertUnprocessable();
        }
        $this->assertSame($original, $entry->fresh()->tasks);
    }

    public function test_attendance_review_preserves_pending_ack_and_duplicate_content_cannot_be_submitted(): void
    {
        [$owner, $head, $employee, $entry] = $this->fixture('submitted');
        $employee->update(['reviewer_user_id' => $head->id]);
        $this->save($owner, $entry)->assertOk();
        $this->submit($owner, $entry->fresh())->assertOk();
        $ack = HrDtrAcknowledgement::sole();
        $entry = app(DtrService::class)->review($head, $entry->fresh(), 'reviewed', 'Attendance checked', 5);
        $this->assertSame(6, $entry->hr_version);
        $this->assertSame('pending', $ack->fresh()->status);
        $this->submit($owner, $entry)->assertConflict();
        $this->actingAs($head)->postJson('https://hr.luvep.com/dtr/acknowledgements/'.$ack->id.'/decision', ['version' => 1, 'decision' => 'acknowledged'])->assertOk();
        $this->assertSame('reviewed', $entry->fresh()->hr_status);
        $this->assertSame(6, $entry->hr_version);
        $this->assertSame(5, $ack->fresh()->report_version);
    }

    public function test_historical_pending_only_task_is_preserved_without_new_task_constraints(): void
    {
        [$owner,,, $entry] = $this->fixture('legacy');
        $pending = [['pending' => 'Follow up', 'pending_done' => false, 'task' => '', 'tid' => 'old-pending']];
        $entry->update(['tasks' => $pending]);
        $normalized = [['pending' => 'Follow up', 'pending_done' => false, 'task' => '', 'tid' => 'old-pending', 'start' => '', 'end' => '', 'category' => 'other', 'reference' => '', 'status' => 'todo']];
        $this->save($owner, $entry, ['tasks' => $normalized, 'remarks' => 'Clarified notes'])->assertOk()->assertJsonPath('entry.can_change_tasks', false);
        $this->assertSame($pending, $entry->fresh()->tasks);
        foreach (['task' => 'Replace pending with task', 'pending' => 'Changed pending', 'pending_done' => true] as $field => $value) {
            $changed = $normalized;
            $changed[0][$field] = $value;
            $this->save($owner, $entry->fresh(), ['tasks' => $changed])->assertUnprocessable();
        }
        $this->assertSame($pending, $entry->fresh()->tasks);
    }

    public function test_live_task_changes_reuse_shift_and_break_validation_and_ignore_client_seconds(): void
    {
        [$owner,, $employee, $entry] = $this->fixture();
        $entry->forceFill(['time_source' => 'live'])->save();
        $shift = HrShift::create(['hr_employee_id' => $employee->id, 'work_date' => '2026-11-17', 'timezone' => 'Asia/Manila', 'started_at' => '2026-11-17 00:00:00', 'ended_at' => '2026-11-17 09:00:00']);
        $shift->breaks()->create(['started_at' => '2026-11-17 04:00:00', 'ended_at' => '2026-11-17 05:00:00']);
        $tasks = [['task' => 'Client coordination', 'status' => 'done', 'start' => '08:00', 'end' => '09:00', 'seconds' => 999999]];
        $this->save($owner, $entry, ['tasks' => $tasks])->assertOk()->assertJsonPath('entry.tasks.0.seconds', 3600)->assertJsonPath('entry.can_change_tasks', true)->assertJsonPath('entry.hr_status', 'reviewed');
        foreach ([['start' => '07:00', 'end' => '08:00'], ['start' => '12:00', 'end' => '13:00']] as $times) {
            $this->save($owner, $entry->fresh(), ['tasks' => [[...$tasks[0], ...$times]]])->assertUnprocessable();
        }
        $this->save($owner, $entry->fresh(), ['tasks' => [$tasks[0], $tasks[0]]])->assertUnprocessable();
        $this->assertSame('08:00', $entry->fresh()->time_in);
        $this->assertSame('17:00', $entry->time_out);
        $this->assertSame(1, HrDtrRevision::count());
    }
}
