<?php

namespace Tests\Feature\Hr;

use App\Models\HrEmployee;
use App\Models\HrPolicy;
use App\Models\User;
use App\Services\Hr\PolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrPoliciesTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['hr.enabled' => true]);
        $this->withoutVite();
        $user = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);
        $hr = User::factory()->create(['role' => 'super_admin']);

        return [$user, $hr];
    }

    public function test_drafts_are_invisible_and_only_authorized_hr_can_publish(): void
    {
        [$user,$hr] = $this->fixture();
        $policy = HrPolicy::create(['draft_title' => 'Private draft', 'draft_body' => 'Not yet published']);
        $this->actingAs($user)->get('https://hr.luvep.com/policies')->assertOk()->assertInertia(fn (Assert $p) => $p->has('policies', 0));
        $this->postJson('https://hr.luvep.com/policies/'.$policy->id.'/publish', ['title' => 'Policy', 'body' => 'Text', 'version' => 0])->assertForbidden();
        $version = app(PolicyService::class)->publish($hr, $policy, 'Published policy', 'Plain text <script>never execute</script>');
        $this->get('https://hr.luvep.com/policies')->assertOk()->assertInertia(fn (Assert $p) => $p->has('policies', 1)->where('policies.0.versions.0.id', $version->id)->missing('policies.0.draft_body'));
    }

    public function test_acknowledgement_is_idempotent(): void
    {
        [$user,$hr] = $this->fixture();
        $policy = HrPolicy::create(['draft_title' => 'Policy', 'draft_body' => 'Text']);
        $v = app(PolicyService::class)->publish($hr, $policy, 'Policy', 'Published text');
        $this->actingAs($user)->postJson('https://hr.luvep.com/policies/versions/'.$v->id.'/acknowledge', ['declaration' => true])->assertOk();
        $this->postJson('https://hr.luvep.com/policies/versions/'.$v->id.'/acknowledge', ['declaration' => true])->assertOk();
        $this->assertDatabaseCount('hr_policy_acknowledgements', 1);
        $this->assertDatabaseHas('hr_policy_acknowledgements', ['user_id' => $user->id, 'hr_policy_version_id' => $v->id]);
    }

    public function test_new_version_requires_new_acknowledgement_and_old_displayed_version_remains_exact(): void
    {
        [$user,$hr] = $this->fixture();
        $p = HrPolicy::create(['draft_title' => 'Policy', 'draft_body' => 'Text']);
        $s = app(PolicyService::class);
        $old = $s->publish($hr, $p, 'Policy', 'Version one');
        $new = $s->publish($hr, $p->fresh(), 'Policy', 'Version two');
        $s->acknowledge($user, $old);
        $this->assertDatabaseMissing('hr_policy_acknowledgements', ['user_id' => $user->id, 'hr_policy_version_id' => $new->id]);
        $this->assertSame('Version one', $old->fresh()->body);
        $s->acknowledge($user, $new);
        $this->assertDatabaseCount('hr_policy_acknowledgements', 2);
    }

    public function test_preview_and_archived_employee_cannot_acknowledge_and_report_is_restricted(): void
    {
        [$user,$hr] = $this->fixture();
        $p = HrPolicy::create(['draft_title' => 'Policy', 'draft_body' => 'Text']);
        $v = app(PolicyService::class)->publish($hr, $p, 'Policy', 'Text');
        $employee = HrEmployee::where('user_id', $user->id)->first();
        $this->actingAs($hr)->withSession(['hr_preview_employee_id' => $employee->id])->postJson('https://hr.luvep.com/policies/versions/'.$v->id.'/acknowledge', ['declaration' => true])->assertForbidden();
        $this->withSession(['hr_preview_employee_id' => null])->actingAs($user)->getJson('https://hr.luvep.com/policies/versions/'.$v->id.'/acknowledgements')->assertForbidden();
        $employee->update(['status' => 'archived']);
        $this->postJson('https://hr.luvep.com/policies/versions/'.$v->id.'/acknowledge', ['declaration' => true])->assertForbidden();
        $this->assertDatabaseCount('hr_policy_acknowledgements',0);
    }
}
