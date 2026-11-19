<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sees_all_modules_and_features(): void
    {
        $u = User::factory()->create(['role' => 'super_admin']);
        $this->assertTrue($u->canSeeModule('dtr'));
        $this->assertTrue($u->canSeeModule('dtr.reports'));
        $this->assertTrue($u->canSeeModule('portal_invitation'));
        $this->assertContains('dtr.summary', $u->grantedModules());
    }

    public function test_admin_gets_admin_default_modules_but_not_super_only(): void
    {
        $u = User::factory()->create(['role' => 'admin', 'module_permissions' => []]);
        // Admins receive the staff reporting, portal invitation and affiliate modules by default.
        $this->assertTrue($u->canSeeModule('dtr'));
        $this->assertTrue($u->canSeeModule('dtr.reports'));
        $this->assertTrue($u->canSeeModule('portal_invitation'));
        $this->assertTrue($u->canSeeModule('agents'));
        $this->assertContains('agents', $u->grantedModules());
        $this->assertFalse($u->canSeeModule('referral_students'));
    }

    public function test_whole_module_grant_implies_every_feature(): void
    {
        $u = User::factory()->create(['role' => 'sales', 'module_permissions' => ['dtr']]);
        $this->assertTrue($u->canSeeModule('dtr'));
        $this->assertTrue($u->canSeeModule('dtr.reports'));
        $this->assertTrue($u->canSeeModule('dtr.manage'));
        $this->assertTrue($u->canSeeModule('dtr.summary'));
        $this->assertContains('dtr.summary', $u->grantedModules());
    }

    public function test_feature_grant_is_scoped(): void
    {
        $u = User::factory()->create(['role' => 'sales', 'module_permissions' => ['dtr.reports']]);
        $this->assertTrue($u->canSeeModule('dtr.reports'));
        $this->assertTrue($u->canSeeModule('dtr'));       // parent visible via feature
        $this->assertFalse($u->canSeeModule('dtr.manage'));
        $this->assertFalse($u->canSeeModule('dtr.summary'));
    }

    public function test_dtr_reports_route_gated_by_feature(): void
    {
        // Granted the reports feature → can open Team Daily Reports.
        $ok = User::factory()->create(['role' => 'sales', 'module_permissions' => ['dtr.reports']]);
        $this->actingAs($ok)->get('/admin/dtr/reports')->assertOk();

        // Only summary granted → cannot open reports.
        $no = User::factory()->create(['role' => 'sales', 'module_permissions' => ['dtr.summary']]);
        $this->actingAs($no)->get('/admin/dtr/reports')->assertForbidden();
        // …but can open summary.
        $this->actingAs($no)->get('/admin/dtr/summary')->assertOk();
    }

    public function test_module_management_update_dedupes_features_under_whole_grant(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $target = User::factory()->create(['role' => 'sales']);

        $this->actingAs($super)->post("/admin/module-management/{$target->id}", [
            'modules' => ['dtr', 'dtr.reports', 'dtr.manage', 'portal_invitation'],
        ])->assertRedirect();

        // 'dtr' whole grant makes the feature keys redundant — they're dropped.
        $this->assertEqualsCanonicalizing(['dtr', 'portal_invitation'], $target->fresh()->module_permissions);
    }
}
