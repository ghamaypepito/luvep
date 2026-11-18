<?php

namespace Tests\Feature\Hr;

use App\Models\HrEmployee;
use App\Models\HrEquipment;
use App\Models\HrEquipmentEvent;
use App\Models\User;
use App\Services\Hr\EquipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HrMyInfoEquipmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hr.enabled' => true]);
        $this->withoutVite();
    }

    private function employee(array $attributes = []): HrEmployee
    {
        return HrEmployee::create(['user_id' => User::factory()->create(['role' => 'sales', ...$attributes])->id, 'status' => 'active']);
    }

    public function test_staff_can_record_only_own_equipment_without_claiming_an_hr_asset(): void
    {
        $owner = $this->employee();
        $other = $this->employee();
        $this->actingAs($owner->user)->postJson('https://hr.luvep.com/equipment/self', ['description' => 'Dell work laptop'])->assertCreated();
        $item = HrEquipment::firstOrFail();
        $this->assertSame($owner->id, $item->hr_employee_id);
        $this->assertSame('self_reported', $item->source);
        $this->assertStringStartsWith('SELF-', $item->asset_tag);
        $this->assertSame('self_reported', HrEquipmentEvent::first()->action);
        foreach (['employee_id' => $other->id, 'asset_tag' => 'HR-001', 'source' => 'hr_issued'] as $key => $value) {
            $this->postJson('https://hr.luvep.com/equipment/self', ['description' => 'Spoofed assignment', $key => $value])->assertUnprocessable();
        }
        $this->assertSame(1, HrEquipment::count());
        $this->actingAs($other->user)->postJson('https://hr.luvep.com/equipment/'.$item->id.'/return', ['version' => $item->version, 'condition' => 'Good'])->assertForbidden();
        $this->actingAs($owner->user)->postJson('https://hr.luvep.com/equipment/'.$item->id.'/return', ['version' => $item->version, 'condition' => 'No longer used'])->assertOk();
        $this->assertSame('returned', $item->fresh()->status);
    }

    public function test_my_info_filters_manager_equipment_to_self_and_staff_cannot_return_hr_issued_items(): void
    {
        $owner = $this->employee(['role' => 'super_admin']);
        $other = $this->employee();
        $service = app(EquipmentService::class);
        $mine = $service->recordOwn($owner->user, 'Own monitor');
        $theirs = $service->issue($owner->user, $other, 'HR-001', 'Company laptop');
        $this->actingAs($owner->user)->get('https://hr.luvep.com/equipment?scope=mine')->assertOk()->assertInertia(fn (Assert $p) => $p->where('scope', 'mine')->has('items', 1)->where('items.0.id', $mine->id)->has('employees', 0));
        $this->get('https://hr.luvep.com/equipment')->assertOk()->assertInertia(fn (Assert $p) => $p->has('items', 2));
        $this->actingAs($other->user)->postJson('https://hr.luvep.com/equipment/'.$theirs->id.'/return', ['version' => $theirs->version, 'condition' => 'Good'])->assertForbidden();
    }

    public function test_active_admin_without_employee_can_add_custom_company_equipment(): void
    {
        $employee = $this->employee();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('https://hr.luvep.com/equipment')->assertOk()->assertInertia(fn (Assert $p) => $p->has('employees', 1)->where('canManageEquipment', true));
        $this->postJson('https://hr.luvep.com/equipment', ['employee_id' => $employee->id, 'asset_tag' => 'HR-ADMIN-CUSTOM', 'description' => 'Custom work equipment'])->assertCreated();
        $this->assertSame('hr_issued', HrEquipment::first()->source);
        $admin->update(['is_active' => false]);
        $this->assertFalse(app(EquipmentService::class)->canManage($admin->fresh()));
    }

    public function test_preview_archived_staff_and_missing_profile_cannot_add_equipment(): void
    {
        $employee = $this->employee();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin)->postJson('https://hr.luvep.com/equipment/self', ['description' => 'No employee profile'])->assertForbidden();
        $this->withSession(['hr_preview_employee_id' => $employee->id])->postJson('https://hr.luvep.com/equipment/self', ['description' => 'Preview write'])->assertForbidden();
        $employee->update(['status' => 'archived']);
        $this->withSession(['hr_preview_employee_id' => null])->actingAs($employee->user)->postJson('https://hr.luvep.com/equipment/self', ['description' => 'Archived write'])->assertForbidden();
        $this->assertSame(0, HrEquipment::count());
    }
}
