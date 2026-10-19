<?php

namespace Tests\Feature\Hr;

use App\Models\HrAuditEvent;
use App\Models\HrEmployee;
use App\Models\User;
use App\Services\Hr\HrAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hr.enabled' => true]);
        $this->withoutVite();
    }

    public function test_ordinary_admin_cannot_grant_itself_medical_access(): void
    {
        $u = User::factory()->create(['role' => 'admin']);
        $this->actingAs($u)->postJson('https://hr.luvep.com/access/'.$u->id, ['grants' => ['documents.medical'], 'version' => 0])->assertForbidden();
        $this->assertFalse(app(HrAccess::class)->allows($u->fresh(), 'documents.medical'));
    }

    public function test_exact_grants_preserve_other_modules_and_versions_and_audit(): void
    {
        $a = User::factory()->create(['role' => 'super_admin']);
        $u = User::factory()->create(['role' => 'sales', 'module_permissions' => ['immigration', 'hr.people']]);
        HrEmployee::create(['user_id' => $u->id, 'status' => 'active']);
        $all = config('hr.abilities');
        $this->actingAs($a)->postJson('https://hr.luvep.com/access/'.$u->id, ['grants' => $all, 'version' => 0])->assertOk();
        foreach ($all as $g) {
            $this->assertTrue(app(HrAccess::class)->allows($u->fresh(), $g), $g);
        }$this->assertContains('immigration', $u->fresh()->module_permissions);
        $this->assertSame(1, $u->fresh()->hr_access_version);
        $this->postJson('https://hr.luvep.com/access/'.$u->id, ['grants' => [], 'version' => 0])->assertStatus(409);
        $this->postJson('https://hr.luvep.com/access/'.$u->id, ['grants' => ['unknown'], 'version' => 1])->assertUnprocessable();
        $this->postJson('https://hr.luvep.com/access/'.$u->id, ['grants' => [], 'version' => 1])->assertOk();
        $this->assertFalse(app(HrAccess::class)->allows($u->fresh(), 'documents.medical'));
        $this->assertSame(2, HrAuditEvent::where('action', 'access.changed')->count());
    }

    public function test_access_admin_cannot_self_elevate_or_remove_last_access_admin(): void
    {
        $a = User::factory()->create(['role' => 'admin', 'module_permissions' => ['hr.access.manage']]);
        $this->actingAs($a)->postJson('https://hr.luvep.com/access/'.$a->id, ['grants' => ['access.manage', 'documents.medical'], 'version' => 0])->assertForbidden();
        $this->postJson('https://hr.luvep.com/access/'.$a->id, ['grants' => [], 'version' => 0])->assertStatus(409);
        $b = User::factory()->create(['role' => 'super_admin']);
        $this->postJson('https://hr.luvep.com/access/'.$a->id, ['grants' => [], 'version' => 0])->assertOk();
        $this->postJson('https://hr.luvep.com/access/'.$b->id, ['grants' => [], 'version' => 0])->assertForbidden();
    }

    public function test_ineligible_target_and_preview_are_denied(): void
    {
        $a = User::factory()->create(['role' => 'super_admin']);
        $u = User::factory()->create(['role' => 'sales']);
        $e = HrEmployee::create(['user_id' => $u->id, 'status' => 'active']);
        $u->update(['is_active' => false]);
        $this->actingAs($a)->postJson('https://hr.luvep.com/access/'.$u->id, ['grants' => ['reports'], 'version' => 0])->assertUnprocessable();
        $u->update(['is_active' => true]);
        $this->withSession(['hr_preview_employee_id' => $e->id])->postJson('https://hr.luvep.com/access/'.$u->id, ['grants' => ['reports'], 'version' => 0])->assertForbidden();
        $this->get('https://hr.luvep.com/access')->assertForbidden();
        $this->get('https://hr.luvep.com/audit')->assertForbidden();
    }

    public function test_audit_requires_explicit_grant_and_never_serializes_private_content(): void
    {
        $a = User::factory()->create(['role' => 'super_admin']);
        $u = User::factory()->create(['role' => 'sales']);
        $e = HrEmployee::create(['user_id' => $u->id, 'status' => 'active']);
        app(\App\Services\Hr\HrAudit::class)->record($a, 'profile.updated', $e, ['employee_id' => $e->id, 'fields' => ['position'], 'private_note' => 'SECRET HR TEXT']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('https://hr.luvep.com/audit')->assertForbidden();
        $admin->update(['module_permissions' => ['hr.audit']]);
        $this->actingAs($admin->fresh())->get('https://hr.luvep.com/audit')->assertOk()->assertDontSee('SECRET HR TEXT');
        $this->actingAs($u)->get('https://hr.luvep.com/audit')->assertForbidden();
    }

    public function test_explicit_hr_administrator_can_enter_without_attendance_enrollment(): void
    {
        $u = User::factory()->create(['role' => 'sales', 'module_permissions' => ['hr.access.manage']]);
        $this->actingAs($u)->get('https://hr.luvep.com/access')->assertOk();
    }

    public function test_permission_mutations_require_csrf_outside_test_bypass(): void
    {
        $u = User::factory()->create(['role' => 'super_admin']);
        $target = User::factory()->create(['role' => 'sales']);
        $this->app->detectEnvironment(fn () => 'local');
        $this->actingAs($u)->postJson('https://hr.luvep.com/access/'.$target->id, ['grants' => ['reports'], 'version' => 0])->assertStatus(419);
    }

    public function test_archived_reviewer_with_audit_grant_cannot_read_previous_team_inboxes(): void
    {
        $u = User::factory()->create(['role' => 'sales', 'module_permissions' => ['hr.audit']]);
        HrEmployee::create(['user_id' => $u->id, 'status' => 'archived']);
        $other = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $other->id, 'status' => 'active', 'manager_user_id' => $u->id, 'reviewer_user_id' => $u->id]);
        $this->actingAs($u)->get('https://hr.luvep.com/dtr/review')->assertForbidden();
        $this->get('https://hr.luvep.com/leave/review')->assertForbidden();
    }
}
