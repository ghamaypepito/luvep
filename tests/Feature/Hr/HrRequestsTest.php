<?php

namespace Tests\Feature\Hr;

use App\Models\HrEmployee;
use App\Models\User;
use App\Services\Hr\RequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrRequestsTest extends TestCase
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

    public function test_employee_sees_own_public_replies_but_no_internal_notes(): void
    {
        [$user,$hr] = $this->fixture();
        $s = app(RequestService::class);
        $case = $s->open($user, 'employment_letter', 'Letter for bank', 'Please prepare a letter', (string) Str::uuid());
        $s->reply($hr, $case, 'INTERNAL PRIVATE NOTE', true);
        $s->reply($hr, $case, 'We are preparing it.', false);
        $this->actingAs($user)->get('https://hr.luvep.com/requests/'.$case->id)->assertOk()->assertInertia(fn (Assert $p) => $p->has('case.messages', 2)->missing('case.version'))->assertDontSee('INTERNAL PRIVATE NOTE');
        $other = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $other->id, 'status' => 'active']);
        $this->actingAs($other)->get('https://hr.luvep.com/requests/'.$case->id)->assertNotFound();
        $this->assertStringNotContainsString('INTERNAL PRIVATE NOTE', \Illuminate\Support\Facades\DB::table('hr_request_messages')->where('internal', true)->value('body'));
    }

    public function test_reassignment_revokes_previous_reviewer(): void
    {
        [$user,$hr] = $this->fixture();
        $s = app(RequestService::class);
        $case = $s->open($user, 'confidential', 'Private matter', 'Private body', (string) Str::uuid());
        $reviewers = [];
        foreach (range(1, 2) as $i) {
            $u = User::factory()->create(['role' => 'sales', 'module_permissions' => ['hr.cases.assigned']]);
            HrEmployee::create(['user_id' => $u->id, 'status' => 'active']);
            $reviewers[] = $u;
        }
        $case = $s->assign($hr, $case, $reviewers[0], 0);
        $this->actingAs($reviewers[0])->get('https://hr.luvep.com/requests/'.$case->id)->assertOk();
        $case = $s->assign($hr, $case, $reviewers[1], $case->version);
        $this->get('https://hr.luvep.com/requests/'.$case->id)->assertNotFound();
        $this->actingAs($reviewers[1])->get('https://hr.luvep.com/requests/'.$case->id)->assertOk();
    }

    public function test_ordinary_admin_gets_only_triage_metadata_and_preview_is_restricted(): void
    {
        [$user,$hr] = $this->fixture();
        $case = app(RequestService::class)->open($user, 'confidential', 'PRIVATE SUBJECT', 'PRIVATE BODY', (string) Str::uuid());
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('https://hr.luvep.com/requests')->assertOk()->assertInertia(fn (Assert $p) => $p->has('requests', 1)->missing('requests.0.subject')->where('requests.0.can_view', false))->assertDontSee('PRIVATE SUBJECT');
        $this->get('https://hr.luvep.com/requests/'.$case->id)->assertNotFound();
        $e = HrEmployee::where('user_id', $user->id)->first();
        $this->withSession(['hr_preview_employee_id' => $e->id])->get('https://hr.luvep.com/requests')->assertOk()->assertInertia(fn (Assert $p) => $p->has('requests', 0));
    }

    public function test_open_is_idempotent_and_closed_case_cannot_receive_replies(): void
    {
        [$user,$hr] = $this->fixture();
        $s = app(RequestService::class);
        $key = (string) Str::uuid();
        $case = $s->open($user, 'general', 'Question', 'Hello', $key);
        $this->assertSame($case->id, $s->open($user, 'general', 'Question', 'Hello', $key)->id);
        $this->assertDatabaseCount('hr_request_messages', 1);
        $s->close($hr, $case, 0);
        $this->actingAs($user)->postJson('https://hr.luvep.com/requests/'.$case->id.'/reply', ['body' => 'Follow up', 'internal' => false])->assertConflict();
    }

    public function test_assignment_requires_case_permission_and_current_version(): void
    {
        [$user,$hr] = $this->fixture();
        $case = app(RequestService::class)->open($user, 'general', 'Question', 'Hello', (string) Str::uuid());
        $unauthorized = User::factory()->create(['role' => 'admin']);
        $this->actingAs($hr)->postJson('https://hr.luvep.com/requests/'.$case->id.'/assign', ['assignee_id' => $unauthorized->id, 'version' => 0])->assertUnprocessable();
        $this->actingAs($user)->postJson('https://hr.luvep.com/requests/'.$case->id.'/reply', ['body' => 'Private note', 'internal' => true])->assertForbidden();
        $reviewer = User::factory()->create(['role' => 'admin', 'module_permissions' => ['hr.cases.assigned']]);
        app(RequestService::class)->assign($hr, $case, $reviewer, 0);
        $this->actingAs($hr)->postJson('https://hr.luvep.com/requests/'.$case->id.'/assign', ['assignee_id' => $reviewer->id, 'version' => 0])->assertConflict();
    }

    public function test_confidential_case_notices_do_not_go_to_unassigned_managers_or_contain_text(): void
    {
        [$user,$hr] = $this->fixture();
        config(['hr.notifications_enabled' => true]);
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Notification::fake();
        $manager = User::factory()->create(['role' => 'sales', 'module_permissions' => ['hr.cases.assigned']]);
        HrEmployee::create(['user_id' => $manager->id, 'status' => 'active']);
        HrEmployee::where('user_id', $user->id)->update(['manager_user_id' => $manager->id]);
        $case = app(RequestService::class)->open($user, 'confidential', 'PRIVATE SUBJECT', 'PRIVATE BODY', (string) Str::uuid());
        $this->assertDatabaseMissing('hr_notification_deliveries', ['user_id' => $manager->id]);
        foreach (\App\Models\HrNotificationDelivery::all() as $delivery) {
            (new \App\Jobs\Hr\DeliverHrNotification($delivery->id))->handle();
        }
        \Illuminate\Support\Facades\Notification::assertSentTo($hr, \App\Notifications\HrActionRequired::class, function ($n, $channels) use ($hr) {
            $data = $n->toArray($hr);
            $this->assertStringNotContainsString('PRIVATE', json_encode($data));

            return $channels === ['mail'];
        });
        $case = app(RequestService::class)->assign($hr,$case,$manager,0);
        $this->assertDatabaseHas('hr_notification_deliveries',['user_id' => $manager->id, 'channel' => 'mail']);
    }
}
