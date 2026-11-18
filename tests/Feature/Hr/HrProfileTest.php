<?php

namespace Tests\Feature\Hr;

use App\Models\HrEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hr.enabled' => true, 'hr.host' => 'hr.luvep.com']);
        $this->withoutVite();
    }

    private function employee(): HrEmployee
    {
        return HrEmployee::create(['user_id' => User::factory()->create(['role' => 'sales'])->id, 'status' => 'active', 'department' => 'Sales', 'emergency_contact' => 'Family 555']);
    }

    public function test_employee_profile_exposes_only_own_allowed_fields(): void
    {
        $employee = $this->employee();
        $this->actingAs($employee->user)->get('https://hr.luvep.com/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('hr/Profile')->where('profile.id', $employee->id)
                ->where('profile.emergency_contact', 'Family 555')->missing('profile.user.password')->missing('profile.user.module_permissions'));
    }

    public function test_employee_cannot_read_other_employee_record_or_roster(): void
    {
        $employee = $this->employee();
        $other = $this->employee();
        $this->actingAs($employee->user)->get('https://hr.luvep.com/people/'.$other->id)->assertNotFound();
        $this->get('https://hr.luvep.com/people')->assertForbidden();
    }

    public function test_profile_update_only_accepts_personal_fields(): void
    {
        $employee = $this->employee();
        $this->actingAs($employee->user)->patch('https://hr.luvep.com/profile', ['phone' => '+639001234567', 'emergency_contact' => 'New contact'])->assertRedirect();
        $this->assertSame('+639001234567', $employee->user->fresh()->phone);
        $this->assertSame('New contact', $employee->fresh()->emergency_contact);
        $this->patchJson('https://hr.luvep.com/profile', ['role' => 'admin', 'status' => 'active', 'module_permissions' => ['hr'], 'manager_user_id' => $employee->user_id])->assertUnprocessable();
        $this->assertSame('sales', $employee->user->fresh()->role);
        $this->assertNull($employee->fresh()->manager_user_id);
    }

    public function test_hr_host_rejects_unrelated_routes_and_external_accounts(): void
    {
        $external = User::factory()->create(['role' => 'agent']);
        $this->actingAs($external)->get('https://hr.luvep.com/')->assertForbidden();
        $this->get('https://hr.luvep.com/activities')->assertNotFound();
        $this->get('https://hr.luvep.com/admin')->assertNotFound();
    }

    public function test_disabled_hr_does_not_expose_portal(): void
    {
        config(['hr.enabled' => false]);
        $this->get('https://hr.luvep.com/')->assertNotFound();
    }
}
