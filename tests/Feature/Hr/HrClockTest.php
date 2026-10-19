<?php

namespace Tests\Feature\Hr;

use App\Models\DtrSetting;
use App\Models\HrEmployee;
use App\Models\HrShift;
use App\Models\User;
use App\Services\Hr\ClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HrClockTest extends TestCase
{
    use RefreshDatabase;

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

    private function act(HrEmployee $e, string $action, int $version, ?string $id = null): HrShift
    {
        return app(ClockService::class)->transition($e, $action, $id ?? (string) Str::uuid(), $version);
    }

    public function test_actual_break_pauses_work_and_resumes_only_after_return(): void
    {
        $e = $this->employee();
        $this->at('2026-10-19 00:30');
        $this->act($e, 'clock_in', 0);
        $this->at('2026-10-19 02:00');
        $this->act($e, 'break_out', 1);
        $this->at('2026-10-19 02:10');
        $state = app(ClockService::class)->state($e);
        $this->assertSame(5400, $state['working_seconds']);
        $this->assertSame(600, $state['break_seconds']);
        $this->at('2026-10-19 02:15');
        $this->act($e, 'break_in', 2);
        $this->at('2026-10-19 04:00');
        $this->act($e, 'clock_out', 3);
        $state = app(ClockService::class)->state($e);
        $this->assertSame(11700, $state['working_seconds']);
        $this->assertSame(900, $state['break_seconds']);
        $this->assertSame('off_shift', $state['status']);
    }

    public function test_clock_out_on_break_closes_both_without_counting_break_time(): void
    {
        $e = $this->employee();
        $this->at('2026-10-19 00:00');
        $this->act($e, 'clock_in', 0);
        $this->at('2026-10-19 01:00');
        $this->act($e, 'break_out', 1);
        $this->at('2026-10-19 02:00');
        $shift = $this->act($e, 'clock_out', 2);
        $this->assertSame(3600, app(ClockService::class)->state($e)['working_seconds']);
        $this->assertSame(0, $shift->breaks()->whereNull('ended_at')->count());
    }

    public function test_retry_is_idempotent_but_stale_or_conflicting_action_is_rejected(): void
    {
        $e = $this->employee();
        $id = (string) Str::uuid();
        $this->at('2026-10-19 00:00');
        $shift = $this->act($e, 'clock_in', 0, $id);
        $this->assertSame($shift->id, $this->act($e, 'clock_in', 0, $id)->id);
        $this->assertSame(1, HrShift::count());
        foreach ([['clock_in', 0, null], ['break_in', 1, null], ['clock_out', 1, $id]] as [$action, $version, $key]) {
            try {
                $this->act($e, $action, $version, $key);
                $this->fail('Conflicting action succeeded');
            } catch (HttpException $ex) {
                $this->assertSame(409, $ex->getStatusCode());
            }
        }
    }

    public function test_closed_shift_cannot_resume_and_multiple_breaks_accumulate(): void
    {
        $e = $this->employee();
        $this->at('2026-10-19 00:00');
        $this->act($e, 'clock_in', 0);
        $this->at('2026-10-19 01:00');
        $this->act($e, 'break_out', 1);
        $this->at('2026-10-19 01:10');
        $this->act($e, 'break_in', 2);
        $this->at('2026-10-19 02:00');
        $this->act($e, 'break_out', 3);
        $this->at('2026-10-19 02:20');
        $this->act($e, 'break_in', 4);
        $this->at('2026-10-19 03:00');
        $this->act($e, 'clock_out', 5);
        $this->assertSame(9000, app(ClockService::class)->state($e)['working_seconds']);
        try {
            $this->act($e, 'break_in', 6);
            $this->fail('Closed shift resumed');
        } catch (HttpException $ex) {
            $this->assertSame(409, $ex->getStatusCode());
        }
    }

    public function test_overnight_nz_dst_uses_elapsed_instants_and_snapshots_finish(): void
    {
        $e = $this->employee(['timezone' => 'Pacific/Auckland', 'sched_in' => '22:00', 'sched_out' => '06:00']);
        $this->at('2026-09-26 10:00');
        $shift = $this->act($e, 'clock_in', 0);
        $this->assertSame('2026-09-26 17:00', $shift->scheduled_end_at->utc()->format('Y-m-d H:i'));
        $e->schedule->update(['sched_out' => '08:00']);
        $this->at('2026-09-26 13:30');
        $this->act($e, 'break_out', 1);
        $this->at('2026-09-26 14:30');
        $this->act($e, 'break_in', 2);
        $this->at('2026-09-26 17:00');
        $this->act($e, 'clock_out', 3);
        $this->assertSame(21600, app(ClockService::class)->state($e)['working_seconds']);
        $this->assertSame('2026-09-26 17:00', $shift->fresh()->scheduled_end_at->utc()->format('Y-m-d H:i'));
    }

    public function test_unscheduled_flexi_shift_has_no_invented_finish(): void
    {
        $e = $this->employee(['schedule_type' => 'flexi', 'sched_in' => null, 'sched_out' => null]);
        $shift = $this->act($e, 'clock_in', 0);
        $this->assertNull($shift->scheduled_end_at);
    }

    public function test_clock_endpoint_ignores_forged_client_time_and_preserves_preview(): void
    {
        config(['hr.enabled' => true]);
        $e = $this->employee();
        $this->at('2026-10-19 00:00');
        $this->actingAs($e->user)->postJson('https://hr.luvep.com/clock', ['action' => 'clock_in', 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'started_at' => '2020-01-01'])->assertOk()->assertJsonPath('status', 'working');
        $this->assertSame('2026-10-19 00:00', HrShift::first()->started_at->utc()->format('Y-m-d H:i'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->withSession(['hr_preview_employee_id' => $e->id])->postJson('https://hr.luvep.com/clock', ['action' => 'clock_out'])->assertForbidden();
    }

    public function test_legacy_clock_routes_use_same_active_shift(): void
    {
        config(['hr.enabled' => true]);
        $e = $this->employee();
        $this->at('2026-10-19 00:00');
        $this->actingAs($e->user)->post('/dtr/time-in')->assertRedirect();
        $this->assertSame(1, HrShift::whereNull('ended_at')->count());
        $this->at('2026-10-19 02:00');
        $this->post('/dtr/time-out')->assertRedirect();
        $this->assertSame(7200, app(ClockService::class)->state($e)['working_seconds']);
    }

    public function test_archived_employee_cannot_bypass_clock_access_through_legacy_route(): void
    {
        config(['hr.enabled' => true]);
        $e = $this->employee();
        $e->update(['status' => 'archived']);
        $this->actingAs($e->user)->post('/dtr/time-in')->assertForbidden();
        $this->post('/dtr/time-out')->assertForbidden();
        $this->assertSame(0, HrShift::count());
    }
}
