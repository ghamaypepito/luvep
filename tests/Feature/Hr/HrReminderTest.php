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
use Tests\TestCase;

class HrReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fixture(): HrEmployee
    {
        config(['hr.enabled' => true]);
        $user = User::factory()->create(['role' => 'sales']);
        $e = HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);
        DtrSetting::create(['user_id' => $user->id, 'timezone' => 'Asia/Manila', 'is_complete' => true, 'schedule_type' => 'fixed', 'sched_in' => '08:00', 'sched_out' => '17:00']);
        Carbon::setTestNow(Carbon::parse('2026-10-19 00:00', 'UTC'));
        app(ClockService::class)->transition($e, 'clock_in', (string) Str::uuid(), 0);
        $this->actingAs($user);

        return $e;
    }

    public function test_reminder_is_claimed_once_in_final_fifteen_minutes(): void
    {
        $this->fixture();
        Carbon::setTestNow(Carbon::parse('2026-10-19 08:44', 'UTC'));
        $this->postJson('https://hr.luvep.com/clock/reminder')->assertOk()->assertJsonPath('notice', null);
        Carbon::setTestNow(Carbon::parse('2026-10-19 08:45', 'UTC'));
        $this->postJson('https://hr.luvep.com/clock/reminder')->assertOk()->assertJsonPath('notice.minutes_remaining', 15);
        $this->postJson('https://luvep.com/hr/clock/reminder')->assertOk()->assertJsonPath('notice', null);
        $this->assertDatabaseCount('hr_shift_reminders', 1);
    }

    public function test_late_wakeup_uses_remaining_minutes_and_no_notice_after_finish(): void
    {
        $this->fixture();
        Carbon::setTestNow(Carbon::parse('2026-10-19 08:55', 'UTC'));
        $this->postJson('https://luvep.com/hr/clock/reminder')->assertOk()->assertJsonPath('notice.minutes_remaining', 5);
        \Illuminate\Support\Facades\DB::table('hr_shift_reminders')->delete();
        Carbon::setTestNow(Carbon::parse('2026-10-19 09:00', 'UTC'));
        $this->postJson('https://luvep.com/hr/clock/reminder')->assertOk()->assertJsonPath('notice', null);
    }

    public function test_closed_shift_flexi_and_preview_never_record_reminders(): void
    {
        $e = $this->fixture();
        $shift = HrShift::first();
        Carbon::setTestNow(Carbon::parse('2026-10-19 08:50', 'UTC'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->withSession(['hr_preview_employee_id' => $e->id]);
        $this->postJson('https://luvep.com/hr/clock/reminder')->assertForbidden();
        $this->getJson('https://luvep.com/hr/clock/reminder/preview')->assertOk()->assertJsonPath('notice.minutes_remaining', 10);
        $this->assertDatabaseCount('hr_shift_reminders', 0);
        $this->withSession(['hr_preview_employee_id' => null])->actingAs($e->user);
        $shift->update(['scheduled_end_at' => null]);
        $this->postJson('https://luvep.com/hr/clock/reminder')->assertOk()->assertJsonPath('notice', null);
        $shift->update(['scheduled_end_at' => '2026-10-19 09:00:00', 'ended_at' => now(), 'active_guard' => null]);
        $this->postJson('https://luvep.com/hr/clock/reminder')->assertOk()->assertJsonPath('notice', null);
    }
}
