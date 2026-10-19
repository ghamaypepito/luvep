<?php

namespace App\Services\Hr;

use App\Models\HrChecklistTask;
use App\Models\HrEmployee;
use App\Models\HrEquipment;
use App\Models\HrEquipmentEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EquipmentService
{
    public function scopedEmployees(User $actor, ?string $kind = null): array
    {
        if (! app(ChecklistService::class)->eligibleOwner($actor)) {
            return [];
        }

        return HrChecklistTask::where('owner_user_id', $actor->id)->whereNull('completed_at')->whereIn('kind', $kind ? [$kind] : ['equipment_issue', 'equipment_return'])->with('checklist')->get()->pluck('checklist.hr_employee_id')->unique()->values()->all();
    }

    public function canHandle(User $actor, HrEmployee $employee, string $kind): bool
    {
        return app(HrAccess::class)->allows($actor, 'operations.manage') || in_array($employee->id, $this->scopedEmployees($actor, $kind), true);
    }

    public function issue(User $actor, HrEmployee $employee, string $assetTag, string $description): HrEquipment
    {
        abort_unless($this->canHandle($actor, $employee, 'equipment_issue'), 403);
        $assetTag = strtoupper(trim($assetTag));
        Validator::make(compact('assetTag', 'description'), ['assetTag' => 'required|string|max:80|regex:/^[A-Z0-9][A-Z0-9._-]*$/', 'description' => 'required|string|max:500'])->validate();

        return DB::transaction(function () use ($actor, $employee, $assetTag, $description) {
            HrEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            abort_if($employee->fresh()->status === 'archived', 409);
            // A locking read after insert-or-ignore sees a concurrent winner even under MySQL REPEATABLE READ.
            HrEquipment::query()->insertOrIgnore(['asset_tag' => $assetTag, 'description' => $description, 'hr_employee_id' => $employee->id, 'status' => 'returned', 'created_at' => now(), 'updated_at' => now()]);
            $item = HrEquipment::where('asset_tag', $assetTag)->lockForUpdate()->firstOrFail();
            abort_if($item->status === 'issued', 409, 'This asset is already assigned. Record its return first.');
            $item->update(['description' => $description, 'hr_employee_id' => $employee->id, 'status' => 'issued', 'issued_at' => now(), 'returned_at' => null, 'condition' => null, 'version' => $item->version + 1]);
            $this->event($actor, $item, 'issued');

            return $item;
        }, 3);
    }

    public function recordReturn(User $actor, HrEquipment $item, string $condition, int $version): HrEquipment
    {
        abort_unless($this->canHandle($actor, $item->employee, 'equipment_return'), 403);
        Validator::make(compact('condition'), ['condition' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $item, $condition, $version) {
            $item = HrEquipment::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canHandle($actor, $item->employee, 'equipment_return'), 403);
            if ($item->status === 'returned') {
                return $item;
            }abort_unless($item->version === $version, 409, 'This asset changed. Refresh before recording its return.');
            $item->update(['status' => 'returned', 'returned_at' => now(), 'condition' => $condition, 'version' => $version + 1]);
            $this->event($actor, $item, 'returned');

            return $item;
        });
    }

    private function event(User $actor, HrEquipment $item, string $action): void
    {
        HrEquipmentEvent::create(['hr_equipment_id' => $item->id, 'hr_employee_id' => $item->hr_employee_id, 'actor_id' => $actor->id, 'action' => $action, 'asset_tag' => $item->asset_tag, 'description' => $item->description, 'condition' => $item->condition, 'created_at' => now()]);
        app(HrAudit::class)->record($actor, 'equipment.'.$action, $item, ['employee_id' => $item->hr_employee_id, 'version' => $item->version]);
        app(HrEvents::class)->record('equipment.'.$action, $item, $actor, 'equipment:'.$item->id.':'.$item->version);
    }
}
