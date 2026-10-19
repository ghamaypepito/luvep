<?php

namespace Tests\Feature\Hr;

use App\Models\DtrSetting;
use App\Models\HrEmployee;
use App\Models\HrEvent;
use App\Models\User;
use App\Services\Hr\MilestoneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrDueWorkTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fixture(): array
    {
        config(['hr.enabled' => true]);
        $this->withoutVite();
        $user = User::factory()->create(['role' => 'sales']);
        $e = HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);
        DtrSetting::create(['user_id' => $user->id, 'timezone' => 'Asia/Manila', 'is_complete' => true]);
        $hr = User::factory()->create(['role' => 'super_admin']);

        return [$e, $hr];
    }

    private function data(User $owner, array $override = []): array
    {
        return [...['kind' => 'probation', 'title' => 'Probation review', 'due_date' => '2026-10-20', 'owner_user_id' => $owner->id, 'employee_visible' => false, 'private_note' => 'PRIVATE REVIEW NOTE', 'version' => 0], ...$override];
    }

    public function test_due_dates_use_employee_timezone_and_repeated_runs_do_not_repeat_events(): void
    {
        [$e,$hr] = $this->fixture();
        $m = app(MilestoneService::class)->save($hr, $e, $this->data($hr));
        Carbon::setTestNow(Carbon::parse('2026-10-19 15:00', 'UTC'));
        $this->artisan('hr:notify-due-work')->assertSuccessful();
        $this->assertSame(0, HrEvent::where('type', 'milestone.due')->count());
        Carbon::setTestNow(Carbon::parse('2026-10-19 16:00', 'UTC'));
        $this->artisan('hr:notify-due-work')->assertSuccessful();
        $this->artisan('hr:notify-due-work')->assertSuccessful();
        $this->assertSame(1, HrEvent::where('type', 'milestone.due')->count());
        $this->assertNull(HrEvent::where('type', 'milestone.due')->first()->actor_id);
        $m = app(MilestoneService::class)->save($hr, $e, $this->data($hr, ['version' => $m->version, 'private_note' => 'Edited note']), $m);
        $this->artisan('hr:notify-due-work')->assertSuccessful();
        $this->assertSame(1, HrEvent::where('type', 'milestone.due')->count());
    }

    public function test_only_shared_milestones_appear_to_employee_without_private_note(): void
    {
        [$e,$hr] = $this->fixture();
        $s = app(MilestoneService::class);
        $s->save($hr, $e, $this->data($hr));
        $s->save($hr, $e, $this->data($hr, ['employee_visible' => true, 'title' => 'Contract review']));
        $this->actingAs($e->user)->get('https://hr.luvep.com/milestones')->assertOk()->assertInertia(fn (Assert $p) => $p->has('milestones', 1)->where('milestones.0.title', 'Contract review')->missing('milestones.0.private_note'))->assertDontSee('PRIVATE REVIEW NOTE');
    }

    public function test_completed_milestones_do_not_alert_and_new_deadline_gets_one_new_event(): void
    {
        [$e,$hr] = $this->fixture();
        $s = app(MilestoneService::class);
        $m = $s->save($hr, $e, $this->data($hr));
        Carbon::setTestNow(Carbon::parse('2026-10-22 00:00', 'UTC'));
        $this->artisan('hr:notify-due-work')->assertSuccessful();
        $m = $s->save($hr, $e, $this->data($hr, ['version' => $m->version, 'due_date' => '2026-10-21']), $m);
        $this->artisan('hr:notify-due-work')->assertSuccessful();
        $this->assertSame(2, HrEvent::where('type', 'milestone.due')->count());
        $s->complete($hr, $m, $m->version);
        $this->artisan('hr:notify-due-work')->assertSuccessful();
        $this->assertSame(2, HrEvent::where('type', 'milestone.due')->count());
    }

    public function test_revoked_owner_is_not_notified_and_hidden_milestone_does_not_alert_employee(): void
    {
        [$e,$hr] = $this->fixture();
        config(['hr.notifications_enabled' => true]);
        Queue::fake();
        $owner = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $owner->id, 'status' => 'active']);
        $m = app(MilestoneService::class)->save($hr, $e, $this->data($owner));
        $owner->update(['is_active' => false]);
        Carbon::setTestNow(Carbon::parse('2026-10-21 00:00', 'UTC'));
        $this->artisan('hr:notify-due-work')->assertSuccessful();
        $event = HrEvent::where('type', 'milestone.due')->first();
        $this->assertDatabaseMissing('hr_notification_deliveries', ['event_id' => $event->id, 'user_id' => $owner->id]);
        $this->assertDatabaseMissing('hr_notification_deliveries',['event_id' => $event->id, 'user_id' => $e->user_id]);
    }
}
