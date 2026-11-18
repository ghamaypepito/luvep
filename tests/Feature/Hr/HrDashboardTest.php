<?php

namespace Tests\Feature\Hr;

use App\Models\DtrLeave;
use App\Models\HrEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_dashboard_excludes_others_absences_and_medical_details(): void
    {
        config(['hr.enabled' => true]);
        $this->withoutVite();
        $user = User::factory()->create(['role' => 'sales']);
        $other = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);
        HrEmployee::create(['user_id' => $other->id, 'status' => 'active']);
        DtrLeave::create(['user_id' => $other->id, 'type' => 'Personal / Sick Leave', 'start_date' => now()->toDateString(), 'end_date' => now()->toDateString(), 'status' => 'approved', 'reason' => 'PRIVATE MEDICAL REASON']);
        $this->actingAs($user)->get('https://hr.luvep.com/')->assertOk()->assertInertia(fn (Assert $p) => $p->missing('absences')->has('upcomingLeave', 0)->missing('reviews')->missing('workInsights'))->assertDontSee('PRIVATE MEDICAL REASON');
    }

    public function test_head_personal_overview_excludes_team_availability(): void
    {
        config(['hr.enabled' => true]);
        $this->withoutVite();
        $head = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $user->id, 'status' => 'active', 'manager_user_id' => $head->id]);
        DtrLeave::create(['user_id' => $user->id, 'type' => 'Personal / Sick Leave', 'start_date' => now()->toDateString(), 'end_date' => now()->toDateString(), 'status' => 'approved', 'reason' => 'PRIVATE MEDICAL REASON']);
        $this->actingAs($head)->get('https://hr.luvep.com/')->assertOk()->assertInertia(fn (Assert $p) => $p->missing('absences')->missing('reviews')->missing('workInsights')->where('hr.can_view_staff_status', true));
    }
}
