<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Route::middleware(['web', 'auth'])->get('/_test/user-view', fn () => inertia('admin/Dashboard', ['effective_id' => auth()->id()]));
        Route::middleware(['web', 'auth', 'module:program_verification.proposal'])->post('/_test/user-action', function () {
            ActivityLog::record('test.action');

            return response()->json(['effective_id' => auth()->id()]);
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function target(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['role' => 'education', 'module_permissions' => ['program_verification.proposal']], $attrs));
    }

    private function start(User $admin, User $target): string
    {
        $this->actingAs($admin)->postJson('/admin/user-view/'.$target->id)->assertOk()->assertJsonPath('redirect', $target->homeRoute());

        return session('user_view.token');
    }

    public function test_super_admin_can_search_and_switch_with_exact_grants_without_changing_login(): void
    {
        $admin = $this->admin();
        $target = $this->target(['name' => 'Dinah Test']);
        $this->actingAs($admin)->getJson('/admin/user-view/users?q=Dinah')->assertOk()->assertJsonPath('users.0.id', $target->id);
        $token = $this->start($admin, $target);
        $this->get('/_test/user-view')->assertOk()->assertInertia(fn (Assert $p) => $p->where('effective_id', $target->id)->where('auth.user.id', $target->id)->where('userView.actor.id', $admin->id)->where('userView.token', $token)->where('auth.can_manage_modules', false));
        $this->assertAuthenticatedAs($admin);
        $this->get('/program-verification')->assertOk();
        $this->get('/consultancy-verification')->assertForbidden();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_real_action_uses_target_permissions_and_audit_retains_both_people(): void
    {
        $admin = $this->admin();
        $target = $this->target();
        $token = $this->start($admin, $target);
        $this->withHeader('X-Luvep-User-View', $token)->postJson('/_test/user-action', ['api_key' => 'never-log-this'])->assertOk()->assertJsonPath('effective_id', $target->id);
        $log = ActivityLog::where('action', 'test.action')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($target->id, $log->metadata['user_view']['target_id']);
        $requestLog = ActivityLog::where('action', 'user_view.action')->firstOrFail();
        $this->assertSame(200, $requestLog->metadata['status']);
        $this->assertStringNotContainsString('never-log-this', $requestLog->toJson());
        $this->assertAuthenticatedAs($admin);
    }

    public function test_ungranted_target_cannot_act_despite_super_admin_actor(): void
    {
        $admin = $this->admin();
        $target = $this->target(['module_permissions' => []]);
        $token = $this->start($admin, $target);
        $this->withHeader('X-Luvep-User-View', $token)->postJson('/_test/user-action')->assertForbidden();
        $this->assertDatabaseMissing('activity_logs', ['action' => 'test.action']);
        $this->assertSame(403, ActivityLog::where('action', 'user_view.action')->firstOrFail()->metadata['status']);
    }

    public function test_stale_tabs_cannot_write_after_switch_or_exit_and_exit_keeps_admin(): void
    {
        $admin = $this->admin();
        $target = $this->target();
        $token = $this->start($admin, $target);
        $this->postJson('/_test/user-action')->assertStatus(409);
        $this->withHeader('X-Luvep-User-View', 'wrong')->postJson('/_test/user-action')->assertStatus(409);
        $this->withHeader('X-Luvep-User-View', $token)->postJson('/admin/user-view/exit')->assertOk();
        $this->assertFalse(session()->has('user_view'));
        $this->postJson('/_test/user-action')->assertStatus(409);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_normal_users_cannot_use_picker_start_or_forge_preview_session(): void
    {
        $user = $this->target();
        $target = $this->target();
        $this->actingAs($user)->getJson('/admin/user-view/users')->assertForbidden();
        $this->postJson('/admin/user-view/'.$target->id)->assertForbidden();
        $this->withSession(['user_view' => ['target_id' => $target->id, 'token' => 'fake']])->get('/_test/user-view')->assertOk()->assertInertia(fn (Assert $p) => $p->where('effective_id', $user->id)->where('userView.available', false));
        $this->assertFalse(session()->has('user_view'));
    }

    public function test_deactivated_target_and_revoked_actor_end_switch_before_action(): void
    {
        $admin = $this->admin();
        $target = $this->target();
        $token = $this->start($admin, $target);
        $target->update(['is_active' => false]);
        $this->withHeader('X-Luvep-User-View', $token)->postJson('/_test/user-action')->assertStatus(409);
        $this->assertFalse(session()->has('user_view'));
        $this->assertDatabaseMissing('activity_logs', ['action' => 'test.action']);
        $target->update(['is_active' => true]);
        $this->withHeader('X-Luvep-User-View', 'none');
        $this->start($admin, $target);
        $admin->update(['role' => 'education', 'module_permissions' => []]);
        $this->get('/_test/user-view')->assertOk()->assertInertia(fn (Assert $p) => $p->where('effective_id', $admin->id)->where('userView.available', false));
        $this->assertFalse(session()->has('user_view'));
    }

    public function test_cannot_start_inactive_or_deleted_user_and_start_clears_old_previews(): void
    {
        $admin = $this->admin();
        $target = $this->target(['is_active' => false]);
        $this->actingAs($admin)->postJson('/admin/user-view/'.$target->id)->assertStatus(422);
        $target->update(['is_active' => true]);
        $this->withSession(['hr_preview_employee_id' => 99, 'lead_portal_preview_id' => 99]);
        $this->start($admin, $target);
        $this->assertFalse(session()->has('hr_preview_employee_id'));
        $this->assertFalse(session()->has('lead_portal_preview_id'));
    }

    public function test_logout_ends_original_session_and_never_logs_out_target_sessions(): void
    {
        $admin = $this->admin();
        $target = $this->target();
        $this->start($admin, $target);
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
        $this->assertFalse(session()->has('user_view'));
    }

    public function test_shared_session_keeps_original_login_and_password_hash(): void
    {
        config(['hr.enabled' => true, 'hr.portal_mode' => true, 'hr.main_url' => 'https://luvep.com']);
        $admin = $this->admin();
        $target = $this->target(['password' => bcrypt('different-password')]);
        Auth::login($admin);
        $key = Auth::guard()->getName();
        $this->withSession(['password_hash_web' => $admin->password])->postJson('https://luvep.com/admin/user-view/'.$target->id)->assertOk();
        $this->get('https://luvep.com/_test/user-view')->assertOk();
        $this->assertSame($admin->id, session($key));
        $this->assertSame($admin->password, session('password_hash_web'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_denied_page_keeps_exit_and_hr_host_controls_remain_accessible(): void
    {
        $admin = $this->admin();
        $target = $this->target();
        $this->start($admin, $target);
        $this->get('/consultancy-verification')->assertForbidden()->assertInertia(fn (Assert $p) => $p->component('UserViewError')->where('userView.active', true)->where('userView.actor.id', $admin->id));
        config(['hr.enabled' => true, 'hr.host' => 'hr.luvep.com']);
        $this->getJson('https://hr.luvep.com/admin/user-view/users')->assertOk();
        $this->postJson('https://hr.luvep.com/admin/user-view/exit')->assertOk()->assertJsonPath('redirect', '/');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_authentication_changes_and_nested_preview_are_blocked(): void
    {
        $admin = $this->admin();
        $target = $this->target();
        $token = $this->start($admin, $target);
        $this->withHeader('X-Luvep-User-View', $token)->postJson('/_test/user-action', ['password' => 'do-not-change'])->assertForbidden();
        $this->assertDatabaseMissing('activity_logs', ['action' => 'test.action']);
    }

    public function test_starting_from_active_hr_preview_clears_readonly_context(): void
    {
        config(['hr.enabled' => true]);
        $admin = $this->admin();
        $employee = \App\Models\HrEmployee::create(['user_id' => $this->target()->id, 'status' => 'active']);
        $this->withSession(['hr_preview_employee_id' => $employee->id]);
        $this->start($admin, $this->target());
        $this->assertFalse(session()->has('hr_preview_employee_id'));
    }

    public function test_all_hr_preview_aliases_are_blocked_and_hr_logout_uses_actor(): void
    {
        config(['hr.enabled' => true]);
        $admin = $this->admin();
        $target = $this->target(['role' => 'admin']);
        $employee = \App\Models\HrEmployee::create(['user_id' => $this->target()->id, 'status' => 'active']);
        $token = $this->start($admin, $target);
        foreach (['/portal/hr/preview/', '/hr/preview/', 'https://hr.luvep.com/preview/'] as $path) {
            $this->withHeader('X-Luvep-User-View', $token)->postJson($path.$employee->id)->assertForbidden();
        }
        $remember = $target->getRememberToken();
        $this->post('https://hr.luvep.com/logout')->assertRedirect();
        $this->assertGuest();
        $this->assertSame($remember, $target->fresh()->getRememberToken());
    }

    public function test_url_bearer_tokens_are_never_written_to_audit_log(): void
    {
        $admin = $this->admin();
        $target = $this->target();
        $token = $this->start($admin, $target);
        $this->withHeader('X-Luvep-User-View', $token)->postJson('/booking/cancel/private-bearer-example');
        $log = ActivityLog::where('action', 'user_view.action')->firstOrFail();
        $this->assertStringNotContainsString('private-bearer-example', $log->toJson());
        $this->assertStringNotContainsString($token, $log->toJson());
    }

    public function test_broken_client_profile_does_not_log_out_super_admin(): void
    {
        $admin = $this->admin();
        $target = $this->target(['role' => 'lead', 'lead_id' => null]);
        $this->start($admin, $target);
        $this->get('/portal/lead/dashboard')->assertForbidden();
        $this->assertAuthenticatedAs($admin);
        $this->postJson('/admin/user-view/exit')->assertOk();
    }
}
