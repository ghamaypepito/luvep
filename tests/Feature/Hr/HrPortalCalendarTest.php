<?php

namespace Tests\Feature\Hr;

use App\Models\DtrLeave;
use App\Models\HrEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrPortalCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hr.enabled' => true, 'hr.portal_mode' => true]);
        $this->withoutVite();
    }

    public function test_password_reset_revokes_main_site_hr_session_without_shared_cookie(): void
    {
        config(['hr.shared_session' => false]);
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->withSession(['password_hash_web' => 'obsolete'])->get('https://luvep.com/portal/hr/dashboard')->assertRedirect();
        $this->assertGuest();
    }

    public function test_pending_manager_with_unrelated_grant_cannot_read_team_leave(): void
    {
        $manager = User::factory()->create(['role' => 'sales', 'module_permissions' => ['hr.documents.manage']]);
        HrEmployee::create(['user_id' => $manager->id, 'status' => 'candidate']);
        $user = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $user->id, 'status' => 'active', 'manager_user_id' => $manager->id]);
        DtrLeave::create(['user_id' => $user->id, 'type' => 'Annual Leave', 'status' => 'approved', 'start_date' => '2026-10-20', 'end_date' => '2026-10-21']);
        $this->actingAs($manager)->get('https://luvep.com/portal/hr/calendar?date=2026-10-20')->assertOk()->assertInertia(fn (Assert $p) => $p->has('events', 0));
    }

    public function test_hr_notifications_keep_hr_layout_on_main_site(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('https://luvep.com/portal/hr/notifications')->assertOk()->assertInertia(fn (Assert $p) => $p->component('hr/Notifications'));
    }

    public function test_guest_login_retains_hr_destination(): void
    {
        $this->get('https://luvep.com/portal/hr/dashboard')->assertRedirect('https://luvep.com/login');
        $this->assertEquals('https://luvep.com/portal/hr/dashboard', session('url.intended'));
    }

    public function test_pending_employee_sees_pending_screen_but_no_records(): void
    {
        $u = User::factory()->create(['role' => 'sales']);
        $this->actingAs($u)->get('https://luvep.com/portal/hr/dashboard')->assertForbidden()->assertInertia(fn (Assert $p) => $p->component('hr/Pending'));
        $this->get('https://luvep.com/portal/hr/calendar')->assertForbidden();
    }

    public function test_calendar_is_scoped_and_omits_private_leave_details(): void
    {
        $u = User::factory()->create(['role' => 'sales']);
        $other = User::factory()->create(['role' => 'sales']);
        foreach ([$u, $other] as $person) {
            HrEmployee::create(['user_id' => $person->id, 'status' => 'active']);
            DtrLeave::create(['user_id' => $person->id, 'type' => 'Personal / Sick Leave', 'reason' => 'SECRET', 'status' => 'approved', 'start_date' => '2026-10-20', 'end_date' => '2026-10-21']);
        }
        $this->actingAs($u)->get('https://luvep.com/portal/hr/calendar?date=2026-10-20&view=month')->assertOk()->assertInertia(fn (Assert $p) => $p->component('hr/Calendar')->has('events', 1)->where('events.0.title', $u->name.' · Approved leave')->missing('events.0.reason')->missing('events.0.type'))->assertDontSee('SECRET');
    }

    public function test_manager_sees_only_assigned_leave_and_date_validation(): void
    {
        $head = User::factory()->create(['role' => 'admin']);
        foreach ([true, false] as $assigned) {
            $u = User::factory()->create(['role' => 'sales']);
            HrEmployee::create(['user_id' => $u->id, 'status' => 'active', 'manager_user_id' => $assigned ? $head->id : null]);
            DtrLeave::create(['user_id' => $u->id, 'type' => 'Annual Leave', 'status' => 'approved', 'start_date' => '2026-10-20', 'end_date' => '2026-10-21']);
        }
        $this->actingAs($head)->get('https://luvep.com/portal/hr/calendar?date=2026-10-20&view=week')->assertOk()->assertInertia(fn (Assert $p) => $p->has('events', 1));
        $this->getJson('https://luvep.com/portal/hr/calendar?date=bad')->assertUnprocessable();
    }

    public function test_approved_leave_adjusts_only_the_affected_dates_and_restores_when_cancelled(): void
    {
        $user = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);
        \App\Models\DtrSetting::create(['user_id' => $user->id, 'is_complete' => true, 'timezone' => 'Asia/Manila', 'sched_in' => '08:00', 'sched_out' => '17:00', 'break_hours' => 1]);
        $leave = DtrLeave::create(['user_id' => $user->id, 'type' => 'Annual Leave', 'status' => 'approved', 'start_date' => '2026-10-21', 'end_date' => '2026-10-22', 'half_day' => 'N/A']);
        $this->actingAs($user)->get('https://luvep.com/portal/hr/calendar?date=2026-10-21&view=day')->assertOk()->assertInertia(fn (Assert $p) => $p->has('events', 1)->where('events.0.detail', 'All day'));
        $this->get('https://luvep.com/portal/hr/calendar?date=2026-10-23&view=day')->assertOk()->assertInertia(fn (Assert $p) => $p->has('events', 1)->where('events.0.kind', 'schedule'));
        $leave->update(['status' => 'cancelled']);
        $this->get('https://luvep.com/portal/hr/calendar?date=2026-10-21&view=day')->assertOk()->assertInertia(fn (Assert $p) => $p->has('events', 1)->where('events.0.kind', 'schedule'));
    }

    public function test_half_day_leave_shortens_shift_only_after_approval(): void
    {
        $user = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);
        \App\Models\DtrSetting::create(['user_id' => $user->id, 'is_complete' => true, 'timezone' => 'Asia/Manila', 'sched_in' => '08:00', 'sched_out' => '17:00', 'break_hours' => 1]);
        $leave = DtrLeave::create(['user_id' => $user->id, 'type' => 'Annual Leave', 'status' => 'pending', 'start_date' => '2026-10-21', 'end_date' => '2026-10-21', 'half_day' => 'AM']);
        $url = 'https://luvep.com/portal/hr/calendar?date=2026-10-21&view=day';
        $this->actingAs($user)->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->where('events.1.detail', '08:00 – 17:00 · Asia/Manila'));
        $leave->update(['status' => 'approved']);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->where('events.1.detail', '13:00 – 17:00 · Asia/Manila'));
        $leave->update(['half_day' => 'PM']);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->where('events.1.detail', '08:00 – 12:00 · Asia/Manila'));
        DtrLeave::create(['user_id' => $user->id, 'type' => 'Annual Leave', 'status' => 'approved', 'start_date' => '2026-10-21', 'end_date' => '2026-10-21', 'half_day' => 'AM']);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->has('events', 2));
    }
}
