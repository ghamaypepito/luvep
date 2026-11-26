<?php

namespace Tests\Feature\Hr;

use App\Models\DtrEntry;
use App\Models\DtrSetting;
use App\Models\HrAuditEvent;
use App\Models\HrEmployee;
use App\Models\HrShift;
use App\Models\User;
use App\Services\Hr\ClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HrAutoClockOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hr.enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(array $schedule = []): HrEmployee
    {
        $user = User::factory()->create(['role' => 'sales']);
        DtrSetting::create(['user_id' => $user->id, 'timezone' => 'Asia/Manila', 'sched_in' => '08:00', 'sched_out' => '17:00', 'is_complete' => true, 'schedule_type' => 'fixed', ...$schedule]);

        return HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'UTC'));
    }

    private function act(HrEmployee $employee, string $action, int $version, ?string $requestId = null): HrShift
    {
        return app(ClockService::class)->transition($employee, $action, $requestId ?? (string) Str::uuid(), $version);
    }

    public function test_scheduler_leaves_a_shift_open_one_second_before_ten_hours(): void
    {
        $employee = $this->employee();
        $this->at('2026-10-19 00:00:00');
        $shift = $this->act($employee, 'clock_in', 0);
        $this->at('2026-10-19 09:59:59');

        $this->artisan('hr:auto-clock-out')->assertSuccessful();

        $this->assertNull($shift->fresh()->ended_at);
        $this->assertSame(1, (int) $employee->fresh()->clock_version);
        $this->assertSame(35999, app(ClockService::class)->state($employee)['working_seconds']);
        $this->assertSame(0, HrAuditEvent::where('action', 'clock.auto_clock_out')->count());
    }

    public function test_scheduler_closes_at_exactly_ten_hours_and_updates_the_live_dtr(): void
    {
        $employee = $this->employee();
        $this->at('2026-10-19 00:00:00');
        $shift = $this->act($employee, 'clock_in', 0);
        $this->at('2026-10-19 10:00:00');

        $this->artisan('hr:auto-clock-out')->assertSuccessful();

        $this->assertSame('2026-10-19 10:00:00', $shift->fresh()->ended_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($shift->fresh()->active_guard);
        $state = app(ClockService::class)->state($employee);
        $this->assertSame('off_shift', $state['status']);
        $this->assertSame(36000, $state['working_seconds']);
        $this->assertFalse($state['can_clock_in']);
        $this->assertTrue($state['completed_today']);
        $this->assertSame('18:00', DtrEntry::where('user_id', $employee->user_id)->value('time_out'));
    }

    public function test_delayed_scheduler_uses_true_cutoff_after_recorded_breaks(): void
    {
        $employee = $this->employee();
        $this->at('2026-10-19 00:00:00');
        $shift = $this->act($employee, 'clock_in', 0);
        $this->at('2026-10-19 02:00:00');
        $this->act($employee, 'break_out', 1);
        $this->at('2026-10-19 02:30:00');
        $this->act($employee, 'break_in', 2);
        $this->at('2026-10-19 05:00:00');
        $this->act($employee, 'break_out', 3);
        $this->at('2026-10-19 05:15:00');
        $this->act($employee, 'break_in', 4);
        $this->at('2026-10-19 14:00:00');

        $this->artisan('hr:auto-clock-out')->assertSuccessful();

        $this->assertSame('2026-10-19 10:45:00', $shift->fresh()->ended_at?->utc()->format('Y-m-d H:i:s'));
        $state = app(ClockService::class)->state($employee);
        $this->assertSame(36000, $state['working_seconds']);
        $this->assertSame(2700, $state['break_seconds']);
        $this->assertSame('18:45', DtrEntry::where('user_id', $employee->user_id)->value('time_out'));
    }

    public function test_open_break_before_the_limit_does_not_progress_work(): void
    {
        $employee = $this->employee();
        $this->at('2026-10-19 00:00:00');
        $shift = $this->act($employee, 'clock_in', 0);
        $this->at('2026-10-19 09:00:00');
        $this->act($employee, 'break_out', 1);
        $this->at('2026-10-19 15:00:00');

        $this->artisan('hr:auto-clock-out')->assertSuccessful();

        $this->assertNull($shift->fresh()->ended_at);
        $this->assertSame(1, $shift->breaks()->whereNull('ended_at')->count());
        $this->assertSame(32400, app(ClockService::class)->state($employee)['working_seconds']);
        $this->act($employee, 'break_in', 2);
        $this->at('2026-10-19 16:00:00');
        $this->artisan('hr:auto-clock-out')->assertSuccessful();
        $this->assertSame('2026-10-19 16:00:00', $shift->fresh()->ended_at?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_overnight_new_zealand_dst_shift_uses_elapsed_utc_time(): void
    {
        $employee = $this->employee(['timezone' => 'Pacific/Auckland', 'sched_in' => '22:00', 'sched_out' => '06:00']);
        $this->at('2026-09-26 10:00:00');
        $shift = $this->act($employee, 'clock_in', 0);
        $this->at('2026-09-26 13:30:00');
        $this->act($employee, 'break_out', 1);
        $this->at('2026-09-26 14:30:00');
        $this->act($employee, 'break_in', 2);
        $this->at('2026-09-26 23:00:00');

        $this->artisan('hr:auto-clock-out')->assertSuccessful();

        $this->assertSame('2026-09-26', $shift->fresh()->work_date);
        $this->assertSame('2026-09-26 21:00:00', $shift->fresh()->ended_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(36000, app(ClockService::class)->state($employee)['working_seconds']);
        $this->assertSame('10:00', DtrEntry::where('user_id', $employee->user_id)->value('time_out'));
    }

    public function test_repeated_scheduler_runs_increment_version_and_record_a_system_audit_only_once(): void
    {
        $employee = $this->employee();
        $this->at('2026-10-19 00:00:00');
        $shift = $this->act($employee, 'clock_in', 0);
        $this->at('2026-10-19 12:00:00');

        $this->artisan('hr:auto-clock-out')->assertSuccessful();
        $this->artisan('hr:auto-clock-out')->assertSuccessful();

        $this->assertSame(2, (int) $employee->fresh()->clock_version);
        $audits = HrAuditEvent::where('action', 'clock.auto_clock_out')->get();
        $this->assertCount(1, $audits);
        $this->assertNull($audits->first()->actor_id);
        $this->assertSame($shift->getMorphClass(), $audits->first()->subject_type);
        $this->assertSame($shift->id, (int) $audits->first()->subject_id);
        $this->assertSame(2, $audits->first()->metadata['version']);
    }

    public function test_state_read_caps_working_totals_without_persisting_clock_changes(): void
    {
        $employee = $this->employee();
        $this->at('2026-10-19 00:00:00');
        $shift = $this->act($employee, 'clock_in', 0);
        $this->at('2026-10-19 12:00:00');

        $state = app(ClockService::class)->state($employee);

        $this->assertSame('working', $state['status']);
        $this->assertSame(36000, $state['working_seconds']);
        $this->assertSame(36000, $state['max_working_seconds']);
        $this->assertSame(1, $state['version']);
        $this->assertNull($state['ended_at']);
        $this->assertNull($shift->fresh()->ended_at);
        $this->assertSame(1, (int) $employee->fresh()->clock_version);
        $this->assertNull(DtrEntry::where('user_id', $employee->user_id)->value('time_out'));
        $this->assertSame(0, HrAuditEvent::where('action', 'clock.auto_clock_out')->count());
    }

    public function test_late_clock_out_is_capped_and_cannot_restart_the_completed_workday(): void
    {
        $employee = $this->employee();
        $this->at('2026-10-19 00:00:00');
        $shift = $this->act($employee, 'clock_in', 0);
        $this->at('2026-10-19 12:00:00');
        $requestId = (string) Str::uuid();

        $closed = $this->act($employee, 'clock_out', 1, $requestId);

        $this->assertSame($shift->id, $closed->id);
        $this->assertSame('2026-10-19 10:00:00', $closed->ended_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($closed->id, $this->act($employee, 'clock_out', 1, $requestId)->id);
        $this->assertSame(2, (int) $employee->fresh()->clock_version);
        try {
            $this->act($employee, 'clock_in', 2);
            $this->fail('An automatically completed workday restarted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(1, HrShift::count());
    }

    public function test_late_break_action_cannot_extend_work_or_roll_back_automatic_closure(): void
    {
        $employee = $this->employee();
        $this->at('2026-10-19 00:00:00');
        $shift = $this->act($employee, 'clock_in', 0);
        $this->at('2026-10-19 12:00:00');

        try {
            $this->act($employee, 'break_out', 1);
            $this->fail('An overdue shift started a break.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame('2026-10-19 10:00:00', $shift->fresh()->ended_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(2, (int) $employee->fresh()->clock_version);
        $this->assertSame(0, $shift->breaks()->count());
    }

    public function test_existing_policy_free_open_shifts_are_left_for_review(): void
    {
        $employee = $this->employee();
        $this->at('2026-10-19 00:00:00');
        $shift = $this->act($employee, 'clock_in', 0);
        $snapshot = $shift->schedule_snapshot;
        unset($snapshot['max_working_seconds']);
        $shift->update(['schedule_snapshot' => $snapshot]);
        $this->at('2026-10-19 12:00:00');

        $this->artisan('hr:auto-clock-out')->assertSuccessful();

        $this->assertNull($shift->fresh()->ended_at);
        $this->assertSame(1, (int) $employee->fresh()->clock_version);
        $this->assertSame(43200, app(ClockService::class)->state($employee)['working_seconds']);
        $this->assertSame(0, HrAuditEvent::where('action', 'clock.auto_clock_out')->count());
    }

    public function test_closed_historical_shifts_and_manual_time_records_are_unchanged(): void
    {
        $employee = $this->employee();
        $this->at('2026-10-19 00:00:00');
        $shift = $this->act($employee, 'clock_in', 0);
        $shift->update(['ended_at' => '2026-10-19 12:00:00', 'active_guard' => null]);
        $entry = DtrEntry::where('user_id', $employee->user_id)->firstOrFail();
        $entry->forceFill(['time_source' => 'manual', 'time_out' => '20:00', 'hr_status' => 'approved'])->save();
        $beforeShift = $shift->fresh()->getAttributes();
        $beforeEntry = $entry->fresh()->getAttributes();
        $this->at('2026-10-21 00:00:00');

        $this->artisan('hr:auto-clock-out')->assertSuccessful();

        $this->assertSame($beforeShift, $shift->fresh()->getAttributes());
        $this->assertSame($beforeEntry, $entry->fresh()->getAttributes());
        $this->assertSame(1, (int) $employee->fresh()->clock_version);
        $this->assertSame(0, HrAuditEvent::where('action', 'clock.auto_clock_out')->count());
    }

    public function test_disabled_hr_prevents_scheduler_writes(): void
    {
        $employee = $this->employee();
        $this->at('2026-10-19 00:00:00');
        $shift = $this->act($employee, 'clock_in', 0);
        $this->at('2026-10-19 12:00:00');
        config(['hr.enabled' => false]);

        $this->artisan('hr:auto-clock-out')->assertSuccessful();

        $this->assertNull($shift->fresh()->ended_at);
        $this->assertSame(1, (int) $employee->fresh()->clock_version);
        $this->assertNull(DtrEntry::where('user_id', $employee->user_id)->value('time_out'));
        $this->assertSame(0, HrAuditEvent::where('action', 'clock.auto_clock_out')->count());
    }

    public function test_dry_run_leaves_overdue_shift_and_dtr_unchanged(): void
    {
        $employee = $this->employee();
        $this->at('2026-10-19 00:00:00');
        $shift = $this->act($employee, 'clock_in', 0);
        $entry = DtrEntry::where('user_id', $employee->user_id)->firstOrFail();
        $beforeShift = $shift->getAttributes();
        $beforeEntry = $entry->getAttributes();
        $this->at('2026-10-19 12:00:00');

        $this->artisan('hr:auto-clock-out', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($beforeShift, $shift->fresh()->getAttributes());
        $this->assertSame($beforeEntry, $entry->fresh()->getAttributes());
        $this->assertSame(1, (int) $employee->fresh()->clock_version);
        $this->assertSame(0, HrAuditEvent::where('action', 'clock.auto_clock_out')->count());
    }
}
