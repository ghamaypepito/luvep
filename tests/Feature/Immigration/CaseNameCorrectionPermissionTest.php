<?php

namespace Tests\Feature\Immigration;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaseNameCorrectionPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_individually_granted_manager_can_correct_a_name_without_admin_or_archive_access(): void
    {
        $this->withoutVite();
        $manager = User::factory()->create(['role' => 'immigration_manager', 'module_permissions' => ['case_name_correction']]);
        $case = Lead::create(['first_name' => 'Original', 'last_name' => 'Applicant', 'email' => 'synthetic@example.test', 'is_immigration_case' => true]);
        $this->actingAs($manager)->get('/portal/immigration/cases/'.$case->id.'/profile')->assertOk()
            ->assertInertia(fn ($page) => $page->where('canCorrectCaseNames', true));
        $this->post('/portal/immigration/cases/'.$case->id.'/personal', ['first_name' => 'Corrected', 'email' => $case->email])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Corrected', $case->fresh()->first_name);
        $this->assertFalse($manager->isAdmin());
        $this->get('/portal/immigration/cases/archived')->assertForbidden();
        $this->get('/portal/immigration/cases')->assertInertia(fn ($page) => $page
            ->where('canCorrectCaseNames', true)->where('canManageCaseArchive', false));
        $audit = ActivityLog::where('action', 'lead.updated')->where('user_id', $manager->id)->latest('id')->firstOrFail();
        $this->assertSame(['old' => 'Original', 'new' => 'Corrected'], $audit->properties['changes']['first_name']);
    }

    public function test_permission_is_per_user_default_off_and_ignores_unknown_or_other_module_grants(): void
    {
        $this->withoutVite();
        $case = Lead::create(['first_name' => 'Original', 'last_name' => 'Applicant', 'email' => 'synthetic@example.test', 'is_immigration_case' => true]);
        foreach ([[], ['client_pathways'], ['case_name_correction.fake']] as $modules) {
            $manager = User::factory()->create(['role' => 'immigration_manager', 'module_permissions' => $modules, 'portal_access' => ['admin']]);
            $this->actingAs($manager)->get('/portal/immigration/cases/'.$case->id.'/profile')->assertOk()
                ->assertInertia(fn ($page) => $page->where('canCorrectCaseNames', false));
            $this->postJson('/portal/immigration/cases/'.$case->id.'/personal', ['first_name' => 'Forged', 'email' => $case->email])
                ->assertUnprocessable()->assertJsonValidationErrors('first_name');
        }
        $this->assertSame('Original', $case->fresh()->first_name);
    }

    public function test_super_admin_can_grant_and_revoke_only_this_option_with_an_audit_and_without_other_access_changes(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $manager = User::factory()->create(['role' => 'immigration_manager', 'module_permissions' => ['dtr.reports'], 'portal_access' => []]);
        $other = User::factory()->create(['role' => 'immigration_manager', 'module_permissions' => []]);
        $this->actingAs($super)->post('/admin/module-management/'.$manager->id,
            ['modules' => ['dtr.reports', 'case_name_correction']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['dtr.reports', 'case_name_correction'], $manager->fresh()->module_permissions);
        $this->assertSame('immigration_manager', $manager->fresh()->role);
        $this->assertSame([], $manager->fresh()->portal_access);
        $this->assertSame([], $other->fresh()->module_permissions);
        $audit = ActivityLog::where('action', 'case.name_correction_permission_changed')->sole();
        $this->assertSame($super->id, $audit->user_id);
        $this->assertSame(['subject_type' => 'User', 'subject_id' => $manager->id, 'old' => false, 'new' => true], $audit->properties);
        $this->post('/admin/module-management/'.$manager->id, ['modules' => ['dtr.reports']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['dtr.reports'], $manager->fresh()->module_permissions);
        $this->assertSame(2, ActivityLog::where('action', 'case.name_correction_permission_changed')->count());
        $case = Lead::create(['first_name' => 'Original', 'last_name' => 'Applicant', 'email' => 'synthetic@example.test', 'is_immigration_case' => true]);
        $this->actingAs($manager->fresh())->postJson('/portal/immigration/cases/'.$case->id.'/personal', ['first_name' => 'Forged', 'email' => $case->email])
            ->assertUnprocessable()->assertJsonValidationErrors('first_name');
    }

    public function test_staff_cannot_self_grant_and_other_roles_cannot_receive_the_name_override(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        foreach (['immigration', 'immigration_adviser', 'sales', 'education', 'agent', 'lead'] as $role) {
            $target = User::factory()->create(['role' => $role, 'module_permissions' => ['dtr.reports']]);
            $this->actingAs($super)->postJson('/admin/module-management/'.$target->id, ['modules' => ['case_name_correction']])
                ->assertUnprocessable()->assertJsonValidationErrors('modules');
            $this->assertSame(['dtr.reports'], $target->fresh()->module_permissions);
        }
        $manager = User::factory()->create(['role' => 'immigration_manager']);
        $this->actingAs($manager)->post('/admin/module-management/'.$manager->id, ['modules' => ['case_name_correction']])->assertForbidden();
    }

    public function test_view_as_preview_and_inactive_accounts_do_not_allow_name_corrections(): void
    {
        $this->withoutVite();
        $manager = User::factory()->create(['role' => 'immigration_manager', 'module_permissions' => ['case_name_correction']]);
        $super = User::factory()->create(['role' => 'super_admin']);
        $case = Lead::create(['first_name' => 'Original', 'last_name' => 'Applicant', 'email' => 'synthetic@example.test', 'is_immigration_case' => true]);
        $this->actingAs($super)->postJson('/admin/user-view/'.$manager->id)->assertOk();
        $token = session('user_view.token');
        $this->withHeader('X-Luvep-User-View', $token)->get('/portal/immigration/cases/'.$case->id.'/profile')->assertOk()
            ->assertInertia(fn ($page) => $page->where('canCorrectCaseNames', false));
        $this->postJson('/portal/immigration/cases/'.$case->id.'/personal', ['first_name' => 'Forged', 'email' => $case->email])
            ->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $request = \Illuminate\Http\Request::create('/');
        $request->setUserResolver(fn () => User::factory()->make(['role' => 'immigration_manager', 'is_active' => false, 'module_permissions' => ['case_name_correction']]));
        $this->assertFalse(\App\Support\CaseRecordAccess::canCorrectNames($request));
        $this->assertSame('Original', $case->fresh()->first_name);
    }

    public function test_granted_manager_can_correct_a_dependant_name_with_a_case_scoped_audit(): void
    {
        $case = Lead::create(['first_name' => 'Original', 'last_name' => 'Applicant', 'is_immigration_case' => true]);
        $dependent = \App\Models\CaseDependent::create(['lead_id' => $case->id, 'relationship' => 'child', 'first_name' => 'Before', 'family_name' => 'Family', 'source' => 'staff']);
        $manager = User::factory()->create(['role' => 'immigration_manager', 'module_permissions' => ['case_name_correction']]);
        $this->actingAs($manager)->putJson('/portal/immigration/cases/'.$case->id.'/dependents/'.$dependent->id,
            ['relationship' => 'child', 'first_name' => 'Corrected', 'family_name' => 'Family'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Corrected', $dependent->fresh()->first_name);
        $audit = ActivityLog::where('action', 'case.dependent_name_corrected')->sole();
        $this->assertSame($manager->id, $audit->user_id);
        $this->assertSame($case->id, $audit->properties['subject_id']);
        $this->assertSame(['old' => 'Before', 'new' => 'Corrected'], $audit->properties['changes']['first_name']);
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Applicant', 'is_immigration_case' => true]);
        $this->putJson('/portal/immigration/cases/'.$other->id.'/dependents/'.$dependent->id,
            ['relationship' => 'child', 'first_name' => 'Forged', 'family_name' => 'Family'])->assertNotFound();
        $this->assertSame('Corrected', $dependent->fresh()->first_name);
    }
}
