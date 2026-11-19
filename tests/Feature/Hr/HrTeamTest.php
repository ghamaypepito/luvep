<?php

namespace Tests\Feature\Hr;

use App\Models\DtrEntry;
use App\Models\DtrLeave;
use App\Models\DtrSetting;
use App\Models\HrBreak;
use App\Models\HrDtrAcknowledgement;
use App\Models\HrEmployee;
use App\Models\HrShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrTeamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hr.enabled' => true]);
        $this->withoutVite();
    }

    private function employee(array $attributes = [], array $userAttributes = []): HrEmployee
    {
        $user = User::factory()->create(['role' => 'sales', ...$userAttributes]);

        return HrEmployee::create(['user_id' => $user->id, 'status' => 'active', ...$attributes]);
    }

    private function shift(HrEmployee $employee, array $attributes = []): HrShift
    {
        return HrShift::create(['hr_employee_id' => $employee->id, 'work_date' => now()->toDateString(), 'timezone' => 'Asia/Manila', 'started_at' => now()->subHours(2), ...$attributes]);
    }

    public function test_admin_staff_status_uses_open_shifts_and_breaks_and_omits_ineligible_staff(): void
    {
        $working = $this->employee(['department' => 'Sales'], ['name' => 'A Working']);
        $onBreak = $this->employee([], ['name' => 'B Break']);
        $offShift = $this->employee([], ['name' => 'C Off']);
        $this->shift($working);
        $shift = $this->shift($onBreak);
        HrBreak::create(['hr_shift_id' => $shift->id, 'started_at' => now()->subMinutes(10)]);
        $closed = $this->shift($offShift, ['ended_at' => now()->subHour()]);
        HrBreak::create(['hr_shift_id' => $closed->id, 'started_at' => now()->subMinutes(90)]);
        $this->employee(['status' => 'archived'], ['name' => 'Hidden archived']);
        $this->employee([], ['name' => 'Hidden inactive', 'is_active' => false]);
        $removed = $this->employee([], ['name' => 'Hidden deleted']);
        $removed->user->delete();
        $scheduleArchived = $this->employee([], ['name' => 'Hidden schedule']);
        DtrSetting::create(['user_id' => $scheduleArchived->user_id, 'archived_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('https://hr.luvep.com/team')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('hr/Team')->where('hr.can_view_staff_status', true)->has('members', 3)
            ->where('members.0.name', 'A Working')->where('members.0.status', 'working')->where('members.0.department', 'Sales')
            ->where('members.1.status', 'on_break')->where('members.2.status', 'off_shift')->where('members.2.started_at', null)
            ->missing('members.0.email')->missing('members.0.emergency_contact')->has('asOf'));
        $this->get('https://hr.luvep.com/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('overview.mode', 'admin')->has('teamOverview.members', 3)
            ->where('teamOverview.summary.working', 1)->where('teamOverview.summary.on_break', 1)->where('teamOverview.summary.off_shift', 1));
    }

    public function test_assigned_head_and_reviewer_only_see_their_active_team(): void
    {
        $head = $this->employee();
        $assigned = $this->employee(['manager_user_id' => $head->user_id], ['name' => 'A Managed']);
        $reviewed = $this->employee(['reviewer_user_id' => $head->user_id], ['name' => 'B Reviewed']);
        $other = $this->employee([], ['name' => 'UNRELATED STAFF']);
        foreach ([$assigned, $other] as $employee) {
            DtrLeave::create(['user_id' => $employee->user_id, 'type' => 'Personal / Sick Leave', 'start_date' => now()->toDateString(), 'end_date' => now()->toDateString(), 'status' => 'approved', 'reason' => 'PRIVATE REASON']);
        }
        foreach ([[$assigned, $head], [$reviewed, $other], [$other, $head], [$head, $other]] as [$author, $recipient]) {
            $entry = DtrEntry::create(['user_id' => $author->user_id, 'work_date' => now()->toDateString()]);
            HrDtrAcknowledgement::create(['dtr_entry_id' => $entry->id, 'head_user_id' => $recipient->user_id, 'report_version' => 1,
                'reason' => 'PRIVATE REPORT CHANGE', 'snapshot' => [], 'changes' => [], 'submitted_at' => now()]);
        }
        $this->actingAs($head->user)->get('https://hr.luvep.com/team')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('members', 2)->where('reviews.acknowledgements', 1)->where('members.0.name', 'A Managed')->where('members.1.name', 'B Reviewed')
            ->has('absences', 1)->where('absences.0.name', 'A Managed')->missing('absences.0.reason')->missing('absences.0.type'))
            ->assertDontSee('UNRELATED STAFF')->assertDontSee('PRIVATE REASON')->assertDontSee('PRIVATE REPORT CHANGE');
        $this->get('https://hr.luvep.com/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('overview.mode', 'head')->has('teamOverview.members', 2)->where('teamOverview.reviews.acknowledgements', 1)
            ->where('ownSummary.pending_changes', 1)->has('teamOverview.absences', 1))
            ->assertDontSee('UNRELATED STAFF')->assertDontSee('PRIVATE REASON')->assertDontSee('PRIVATE REPORT CHANGE');

        $head->user->update(['module_permissions' => ['hr.reports']]);
        $this->get('https://hr.luvep.com/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('teamOverview.members', 4)->where('teamOverview.reviews.acknowledgements', 1));

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('https://hr.luvep.com/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('teamOverview.members', 4)->where('teamOverview.reviews.acknowledgements', 4));
        $this->withSession(['hr_preview_employee_id' => $head->id])->get('https://hr.luvep.com/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('overview.mode', 'employee')->where('teamOverview', null)->where('ownSummary.pending_changes', 1));
    }

    public function test_plain_employee_and_parent_hr_grant_cannot_read_staff_status(): void
    {
        $employee = $this->employee([], ['module_permissions' => ['hr']]);
        $this->employee();
        $this->actingAs($employee->user)->get('https://hr.luvep.com/team')->assertForbidden();
        $this->actingAs($employee->user)->get('https://hr.luvep.com/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('hr.can_view_staff_status', false)->where('overview.mode', 'employee')->where('teamOverview', null));
    }

    public function test_explicit_report_viewer_can_read_status_and_preview_cannot_inherit_it(): void
    {
        $viewer = $this->employee([], ['module_permissions' => ['hr.reports']]);
        $subject = $this->employee();
        $this->actingAs($viewer->user)->get('https://hr.luvep.com/team')->assertOk()->assertInertia(fn (Assert $p) => $p->has('members', 2));
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->withSession(['hr_preview_employee_id' => $subject->id])->get('https://hr.luvep.com/team')->assertForbidden();
        $this->get('https://hr.luvep.com/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('hr.can_view_staff_status', false)->where('overview.mode', 'employee')->where('teamOverview', null));
    }
}
