<?php

namespace Tests\Feature\Hr;

use App\Models\HrEmployee;
use App\Models\User;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HrNavigationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function contextFor(User $user): array
    {
        $request = Request::create('/admin/dashboard');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);

        return app(HrContext::class)->props($request);
    }

    public function test_shared_navigation_has_a_bounded_query_count(): void
    {
        config(['hr.enabled' => true]);
        $user = User::factory()->create(['role' => 'super_admin']);
        HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $props = $this->contextFor($user);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($user->name, $props['employee']['name']);
        $this->assertSame(array_fill_keys(config('hr.abilities'), true), $props['permissions']);
        $this->assertLessThanOrEqual(6, $queries, "Shared HR navigation ran {$queries} queries for one employee.");
    }

    public function test_navigation_permissions_match_action_permissions_and_recheck_revocations(): void
    {
        $user = User::factory()->create(['role' => 'education', 'module_permissions' => ['hr.people', 'hr.documents.medical']]);
        $employee = HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);
        $access = app(HrAccess::class);
        $expected = [];
        foreach (config('hr.abilities') as $ability) {
            $expected[$ability] = $access->allows($user, $ability);
        }
        $this->assertSame($expected, $this->contextFor($user)['permissions']);

        $user->update(['module_permissions' => []]);
        $this->assertFalse($this->contextFor($user)['permissions']['documents.medical']);
        $employee->update(['status' => 'archived']);
        $props = $this->contextFor($user);
        $this->assertNull($props['employee']);
        $this->assertSame(array_fill_keys(config('hr.abilities'), false), $props['permissions']);
    }

    public function test_manager_and_time_reviewer_navigation_is_preserved_without_broad_grants(): void
    {
        $manager = User::factory()->create(['role' => 'education']);
        HrEmployee::create(['user_id' => $manager->id, 'status' => 'active']);
        $report = User::factory()->create(['role' => 'education']);
        HrEmployee::create(['user_id' => $report->id, 'status' => 'active', 'manager_user_id' => $manager->id, 'reviewer_user_id' => $manager->id]);

        $props = $this->contextFor($manager);
        $this->assertTrue($props['permissions']['leave.review']);
        $this->assertTrue($props['permissions']['dtr.review']);
        $this->assertFalse($props['permissions']['documents.medical']);
        $this->assertFalse($props['permissions']['access.manage']);
    }
}
