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
}
