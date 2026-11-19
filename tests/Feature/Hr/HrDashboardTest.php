<?php

namespace Tests\Feature\Hr;

use App\Models\DtrEntry;
use App\Models\DtrLeave;
use App\Models\DtrSetting;
use App\Models\HrDtrAcknowledgement;
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
        $this->actingAs($user)->get('https://hr.luvep.com/')->assertOk()->assertInertia(fn (Assert $p) => $p->missing('absences')->has('upcomingLeave', 0)->missing('reviews')->missing('workInsights')->where('overview.mode', 'employee')->where('teamOverview', null))->assertDontSee('PRIVATE MEDICAL REASON');
    }

    public function test_unenrolled_admin_overview_contains_team_data_and_no_employee_profile(): void
    {
        config(['hr.enabled' => true]);
        $this->withoutVite();
        $head = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $user->id, 'status' => 'active', 'manager_user_id' => $head->id]);
        DtrLeave::create(['user_id' => $user->id, 'type' => 'Personal / Sick Leave', 'start_date' => now()->toDateString(), 'end_date' => now()->toDateString(), 'status' => 'approved', 'reason' => 'PRIVATE MEDICAL REASON']);
        $this->actingAs($head)->get('https://hr.luvep.com/')->assertOk()->assertInertia(fn (Assert $p) => $p->missing('absences')->missing('reviews')->missing('workInsights')->where('hr.can_view_staff_status', true)
            ->where('overview.mode', 'admin')->where('profile.id', null)->where('ownSummary.week_reports', 0)
            ->where('ownSummary.pending_changes', 0)->has('teamOverview.members', 1)->where('teamOverview.summary.off_shift', 1)
            ->missing('teamOverview.absences.0.reason'))->assertDontSee('PRIVATE MEDICAL REASON');
    }

    public function test_week_and_pending_changes_use_local_monday_and_report_owner(): void
    {
        config(['hr.enabled' => true]);
        $this->withoutVite();
        $this->travelTo(now('UTC')->setDate(2026, 11, 15)->setTime(17, 0));
        $user = User::factory()->create(['role' => 'sales']);
        $admin = User::factory()->create(['role' => 'admin']);
        $employee = HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);
        DtrSetting::create(['user_id' => $user->id, 'timezone' => 'Asia/Manila']);
        foreach ([
            [$user->id, '2026-11-15', 'pending'],
            [$user->id, '2026-11-16', 'acknowledged'],
            [$user->id, '2026-11-17', null],
            [$admin->id, '2026-11-16', 'pending'],
        ] as [$owner, $date, $status]) {
            $entry = DtrEntry::create(['user_id' => $owner, 'work_date' => $date]);
            if ($status) {
                HrDtrAcknowledgement::create(['dtr_entry_id' => $entry->id, 'head_user_id' => $admin->id, 'report_version' => 1,
                    'status' => $status, 'reason' => 'Private report change', 'snapshot' => [], 'changes' => [], 'submitted_at' => now()]);
            }
        }

        $assertOverview = fn (Assert $p) => $p->where('overview.mode', 'employee')
            ->where('overview.timezone', 'Asia/Manila')->where('overview.today', '2026-11-16')
            ->where('overview.server_now', '2026-11-15T17:00:00.000000Z')
            ->where('ownSummary.week_from', '2026-11-16')->where('ownSummary.week_to', '2026-11-16')
            ->where('ownSummary.week_reports', 1)->where('ownSummary.pending_changes', 1)->where('teamOverview', null);

        $this->actingAs($user)->get('https://hr.luvep.com/')->assertOk()->assertInertia($assertOverview);
        $this->actingAs($admin)->withSession(['hr_preview_employee_id' => $employee->id])->get('https://hr.luvep.com/')
            ->assertOk()->assertInertia($assertOverview)->assertDontSee('Private report change');
    }
}
