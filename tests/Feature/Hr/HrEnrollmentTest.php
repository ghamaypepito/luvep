<?php

namespace Tests\Feature\Hr;

use App\Models\DtrSetting;
use App\Models\HrEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config(['hr.enabled' => true]);

        return User::factory()->create(['role' => 'admin']);
    }

    public function test_enrollment_is_candidate_first_and_rejects_external_accounts(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => 'sales']);
        $client = User::factory()->create(['role' => 'client']);
        $this->actingAs($admin)->postJson('https://hr.luvep.com/people', ['user_id' => $staff->id])->assertCreated()->assertJsonPath('employee.status', 'candidate');
        $this->postJson('https://hr.luvep.com/people', ['user_id' => $client->id])->assertUnprocessable();
        $this->postJson('https://hr.luvep.com/people', ['user_id' => $staff->id])->assertUnprocessable();
    }

    public function test_activation_requires_schedule_assignments_and_current_version(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => 'sales']);
        $e = HrEmployee::create(['user_id' => $staff->id, 'status' => 'candidate']);
        $url = 'https://hr.luvep.com/people/'.$e->id;
        $this->actingAs($admin)->patchJson($url, ['version' => 0, 'status' => 'active'])->assertUnprocessable();
        $data = ['version' => 0, 'status' => 'active', 'department' => 'Operations', 'position' => 'Coordinator', 'manager_user_id' => $admin->id, 'reviewer_user_id' => $admin->id, 'timezone' => 'Asia/Manila', 'team' => 'Philippines', 'schedule_type' => 'fixed', 'sched_in' => '08:00', 'sched_out' => '17:00', 'working_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat']];
        $this->patchJson($url, $data)->assertOk();
        $this->assertTrue(DtrSetting::where('user_id', $staff->id)->first()->is_complete);
        $this->patchJson($url, $data)->assertConflict();
        $this->actingAs($staff)->patchJson($url, [...$data, 'version' => 1])->assertForbidden();
    }

    public function test_enrollment_cannot_assign_permissions_or_self_as_reviewer(): void
    {
        $admin = $this->admin();
        $e = HrEmployee::create(['user_id' => $admin->id, 'status' => 'candidate']);
        $this->actingAs($admin)->patchJson('https://hr.luvep.com/people/'.$e->id, ['version' => 0, 'status' => 'candidate', 'role' => 'super_admin'])->assertUnprocessable();
        $this->patchJson('https://hr.luvep.com/people/'.$e->id, ['version' => 0, 'status' => 'candidate', 'manager_user_id' => $admin->id])->assertUnprocessable();
    }
}
