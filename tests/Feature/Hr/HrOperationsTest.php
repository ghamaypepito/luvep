<?php

namespace Tests\Feature\Hr;

use App\Models\HrChecklistTemplate;
use App\Models\HrEmployee;
use App\Models\User;
use App\Services\Hr\ChecklistService;
use App\Services\Hr\EquipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['hr.enabled' => true]);
        $this->withoutVite();
        $user = User::factory()->create(['role' => 'sales']);
        $e = HrEmployee::create(['user_id' => $user->id, 'status' => 'active']);
        $hr = User::factory()->create(['role' => 'super_admin']);

        return [$e, $hr];
    }

    private function template(array $task = []): HrChecklistTemplate
    {
        return HrChecklistTemplate::create(['name' => 'Starter checklist', 'kind' => 'onboarding', 'tasks' => [[...['title' => 'Read welcome guide', 'kind' => 'general', 'owner_user_id' => null, 'employee_visible' => true, 'note' => 'Welcome', 'private_note' => 'PRIVATE HR NOTE'], ...$task]]]);
    }

    public function test_active_checklist_keeps_template_snapshot_and_employee_never_sees_private_notes(): void
    {
        [$e,$hr] = $this->fixture();
        $template = $this->template();
        $checklist = app(ChecklistService::class)->start($hr, $e, $template, '2026-10-25');
        $template->update(['tasks' => [['title' => 'Changed template']]]);
        $this->assertSame('Read welcome guide', $checklist->tasks->first()->title);
        $this->actingAs($e->user)->get('https://hr.luvep.com/checklists')->assertOk()->assertInertia(fn (Assert $p) => $p->has('checklists', 1)->missing('checklists.0.tasks.0.private_note')->has('templates', 0))->assertDontSee('PRIVATE HR NOTE');
    }

    public function test_access_removal_task_does_not_change_user(): void
    {
        [$e,$hr] = $this->fixture();
        $template = $this->template(['title' => 'Review account access removal', 'kind' => 'access_removal', 'owner_user_id' => $hr->id, 'employee_visible' => false]);
        $template->update(['kind' => 'exit']);
        $c = app(ChecklistService::class)->start($hr, $e, $template, '2026-10-25');
        $task = $c->tasks->first();
        app(ChecklistService::class)->complete($hr, $task, 0);
        $this->assertTrue($e->user->refresh()->is_active);
        $this->assertNotNull($task->fresh()->completed_at);
        $events = \App\Models\HrAuditEvent::count();
        app(ChecklistService::class)->complete($hr, $task, 0);
        $this->assertSame($events, \App\Models\HrAuditEvent::count());
    }

    public function test_only_assigned_owner_or_hr_can_complete_task(): void
    {
        [$e,$hr] = $this->fixture();
        $c = app(ChecklistService::class)->start($hr, $e, $this->template(), '2026-10-25');
        $task = $c->tasks->first();
        $other = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $other->id, 'status' => 'active']);
        $this->actingAs($other)->postJson('https://hr.luvep.com/checklists/tasks/'.$task->id.'/complete', ['version' => 0])->assertForbidden();
        $this->actingAs($e->user)->postJson('https://hr.luvep.com/checklists/tasks/'.$task->id.'/complete', ['version' => 0])->assertOk();
        $this->assertNotNull($c->fresh()->completed_at);
    }

    public function test_equipment_requires_return_before_reissue_and_preserves_history(): void
    {
        [$e,$hr] = $this->fixture();
        $other = HrEmployee::create(['user_id' => User::factory()->create(['role' => 'sales'])->id, 'status' => 'active']);
        $s = app(EquipmentService::class);
        $item = $s->issue($hr, $e, 'LAP-001', 'Work laptop');
        $this->actingAs($hr)->postJson('https://hr.luvep.com/equipment', ['employee_id' => $other->id, 'asset_tag' => 'LAP-001', 'description' => 'Work laptop'])->assertConflict();
        $item = $s->recordReturn($hr, $item, 'Good condition', $item->version);
        $item = $s->issue($hr, $other, 'LAP-001', 'Work laptop');
        $this->assertSame($other->id, $item->hr_employee_id);
        $this->assertDatabaseCount('hr_equipment_events', 3);
        $this->actingAs($e->user)->get('https://hr.luvep.com/equipment')->assertOk()->assertInertia(fn (Assert $p) => $p->has('items', 0)->has('history', 2));
    }

    public function test_operations_owner_has_only_scoped_equipment_access(): void
    {
        [$e,$hr] = $this->fixture();
        $ops = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $ops->id, 'status' => 'active']);
        $template = $this->template(['title' => 'Issue laptop', 'kind' => 'equipment_issue', 'owner_user_id' => $ops->id, 'employee_visible' => false]);
        app(ChecklistService::class)->start($hr, $e, $template, '2026-10-25');
        $this->actingAs($ops)->postJson('https://hr.luvep.com/equipment', ['employee_id' => $e->id, 'asset_tag' => 'OPS-001', 'description' => 'Laptop'])->assertCreated();
        $other = HrEmployee::create(['user_id' => User::factory()->create(['role' => 'sales'])->id, 'status' => 'active']);
        $this->postJson('https://hr.luvep.com/equipment', ['employee_id' => $other->id, 'asset_tag' => 'OPS-002', 'description' => 'Laptop'])->assertForbidden();
    }

    public function test_template_cannot_inject_completed_state_and_preview_cannot_complete(): void
    {
        [$e,$hr] = $this->fixture();
        $this->actingAs($hr)->postJson('https://hr.luvep.com/checklists/templates', ['name' => 'Unsafe', 'kind' => 'onboarding', 'version' => 0, 'tasks' => [['title' => 'Task', 'kind' => 'general', 'employee_visible' => true, 'completed_at' => now()->toISOString()]]])->assertUnprocessable();
        $c = app(ChecklistService::class)->start($hr, $e, $this->template(), '2026-10-25');
        $this->withSession(['hr_preview_employee_id' => $e->id])->postJson('https://hr.luvep.com/checklists/tasks/'.$c->tasks->first()->id.'/complete', ['version' => 0])->assertForbidden();
    }

    public function test_stale_equipment_permission_cannot_return_new_custodians_asset(): void
    {
        [$e,$hr] = $this->fixture();
        $ops = User::factory()->create(['role' => 'sales']);
        HrEmployee::create(['user_id' => $ops->id, 'status' => 'active']);
        app(ChecklistService::class)->start($hr, $e, $this->template(['kind' => 'equipment_return', 'owner_user_id' => $ops->id]), '2026-10-25');
        $s = app(EquipmentService::class);
        $item = $s->issue($hr, $e, 'RETURN-001', 'Laptop');
        $stale = clone $item;
        $s->recordReturn($hr, $item, 'Good', $item->version);
        $other = HrEmployee::create(['user_id' => User::factory()->create(['role' => 'sales'])->id, 'status' => 'active']);
        $s->issue($hr, $other, 'RETURN-001', 'Laptop');
        try {
            $s->recordReturn($ops, $stale, 'Good', $stale->version);
            $this->fail('Old custody scope was reused');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $ex) {
            $this->assertSame(403,$ex->getStatusCode());
        }
    }
}
