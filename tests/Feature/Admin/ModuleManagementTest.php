<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_module_management(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->get('/admin/module-management')
            ->assertOk();
    }

    public function test_plain_admin_cannot_view_module_management(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/module-management')
            ->assertForbidden();
    }

    public function test_super_admin_can_grant_a_module_to_a_user(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->assertFalse($admin->canSeeModule('agents'));

        $this->actingAs($super)
            ->post("/admin/module-management/{$admin->id}", ['modules' => ['agents']])
            ->assertRedirect();

        $this->assertTrue($admin->fresh()->canSeeModule('agents'));
        $this->assertContains('agents', $admin->fresh()->grantedModules());
    }

    public function test_client_pathways_can_be_granted_individually_without_changing_roles_or_portals(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        foreach (['education', 'immigration_manager'] as $role) {
            $staff = User::factory()->create(['role' => $role, 'module_permissions' => ['dtr.reports'], 'portal_access' => []]);
            $this->assertFalse($staff->canSeeModule('client_pathways'));
            $this->actingAs($super)->post("/admin/module-management/{$staff->id}", [
                'modules' => ['dtr.reports', 'client_pathways'],
            ])->assertRedirect()->assertSessionHasNoErrors();
            $staff->refresh();
            $this->assertTrue($staff->canSeeModule('client_pathways'));
            $this->assertSame($role, $staff->role);
            $this->assertSame([], $staff->portal_access);
            $this->assertContains('dtr.reports', $staff->grantedModules());
            $this->assertContains('client_pathways', $staff->grantedModules());
            $this->actingAs($staff)->post("/admin/module-management/{$staff->id}", ['modules' => []])->assertForbidden();
        }
    }

    public function test_invalid_module_key_is_rejected(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($super)
            ->post("/admin/module-management/{$admin->id}", ['modules' => ['not_a_real_module']])
            ->assertSessionHasErrors('modules.0');

        // Nothing was stored (the invalid key was rejected).
        $this->assertEmpty($admin->fresh()->module_permissions ?? []);
        $this->assertNotContains('not_a_real_module', $admin->fresh()->grantedModules());
    }

    public function test_super_admin_sees_every_restricted_module_implicitly(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);

        $this->assertTrue($super->canSeeModule('agents'));
        $this->assertContains('agents', $super->grantedModules());
    }

    public function test_super_only_modules_hidden_from_ungranted_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // super-only restricted modules stay hidden until granted…
        $this->assertFalse($admin->canSeeModule('agents'));
        $this->assertNotContains('agents', $admin->grantedModules());

        // …but admin_default modules (DTR, Portal Invitations, Program
        // Verification) are always visible to admins.
        $this->assertTrue($admin->canSeeModule('dtr'));
        $this->assertTrue($admin->canSeeModule('portal_invitation'));
        $this->assertTrue($admin->canSeeModule('program_verification'));
    }
}
