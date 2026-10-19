<?php

namespace Tests\Feature\Hr;

use App\Models\HrAuditEvent;
use App\Models\HrEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrPreviewTest extends TestCase
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
        return HrEmployee::create(['user_id' => User::factory()->create(['role' => 'sales'])->id, 'status' => 'active']);
    }

    public function test_admin_previews_employee_without_changing_authenticated_identity(): void
    {
        $employee = $this->employee();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('https://hr.luvep.com/preview/'.$employee->id)->assertRedirect('https://hr.luvep.com/');
        $this->assertAuthenticatedAs($admin);
        $this->get('https://hr.luvep.com/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('hr/Dashboard')
            ->where('hr.preview.employee_name', $employee->user->name)->where('hr.preview.read_only', true)
            ->where('hr.permissions.people', false)->where('profile.id', $employee->id));
        $this->post('https://hr.luvep.com/preview/exit')->assertRedirect();
        $this->assertFalse(session()->has('hr_preview_employee_id'));
        $this->assertSame(2, HrAuditEvent::whereIn('action', ['preview.started', 'preview.ended'])->where('actor_id', $admin->id)->count());
    }

    public function test_preview_denies_mutations_on_both_hosts_but_allows_exit(): void
    {
        $employee = $this->employee();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->withSession(['hr_preview_employee_id' => $employee->id]);
        foreach (['https://hr.luvep.com/profile', 'https://luvep.com/dtr/time-in', 'https://luvep.com/dtr/entry', 'https://luvep.com/profile/avatar', 'https://luvep.com/admin/dtr/entry'] as $url) {
            $this->postJson($url, [])->assertForbidden();
        }
        $this->patchJson('https://hr.luvep.com/profile', ['phone' => 'changed'])->assertForbidden();
        $this->post('https://hr.luvep.com/preview/exit')->assertRedirect();
    }

    public function test_employee_cannot_start_preview_or_see_employee_picker(): void
    {
        $employee = $this->employee();
        $other = $this->employee();
        $this->actingAs($employee->user)->post('https://hr.luvep.com/preview/'.$other->id)->assertForbidden();
        $this->get('https://hr.luvep.com/preview')->assertForbidden();
    }

    public function test_revoked_preview_permission_or_archived_target_clears_context(): void
    {
        $employee = $this->employee();
        $staff = User::factory()->create(['role' => 'sales', 'module_permissions' => ['hr.preview']]);
        $this->actingAs($staff)->post('https://hr.luvep.com/preview/'.$employee->id)->assertRedirect();
        $staff->update(['module_permissions' => []]);
        $this->get('https://hr.luvep.com/')->assertForbidden();
        $this->assertFalse(session()->has('hr_preview_employee_id'));
        $admin = User::factory()->create(['role' => 'admin']);
        $employee->update(['status' => 'archived']);
        $this->actingAs($admin)->withSession(['hr_preview_employee_id' => $employee->id])->get('https://hr.luvep.com/')->assertForbidden();
        $this->assertFalse(session()->has('hr_preview_employee_id'));
    }
}
