<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrEmployee;
use App\Models\HrEquipment;
use App\Models\HrEquipmentEvent;
use App\Services\Hr\EquipmentService;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EquipmentController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        $context = app(HrContext::class);
        $preview = $context->isPreview($request);
        $subject = $preview ? $context->subject($request) : app(HrAccess::class)->employee($actor);
        $manage = ! $preview && app(HrAccess::class)->allows($actor, 'operations.manage');
        $service = app(EquipmentService::class);
        $scope = $preview ? [] : $service->scopedEmployees($actor);
        $ids = array_values(array_unique([...$scope, ...($subject ? [$subject->id] : [])]));
        $items = HrEquipment::with('employee.user')->when(! $manage, fn ($q) => $q->whereIn('hr_employee_id', $ids))->latest('id')->limit(200)->get()->map(fn ($i) => [...$i->only(['id', 'asset_tag', 'description', 'status', 'version', 'issued_at', 'returned_at', 'condition']), 'employee_name' => $i->employee->user?->name, 'can_return' => ! $preview && $service->canHandle($actor, $i->employee, 'equipment_return')]);
        $history = HrEquipmentEvent::when(! $manage, fn ($q) => $q->whereIn('hr_employee_id', $ids))->latest('id')->limit(200)->get()->map(fn ($e) => $e->only(['id', 'asset_tag', 'description', 'action', 'condition', 'created_at']));
        $issueIds = $preview ? [] : $service->scopedEmployees($actor, 'equipment_issue');
        $employees = $preview ? [] : HrEmployee::with('user')->where('status', '!=', 'archived')->when(! $manage, fn ($q) => $q->whereIn('id', $issueIds))->get()->map(fn ($e) => ['id' => $e->id, 'name' => $e->user?->name]);

        return Inertia::render('hr/Equipment', compact('items', 'history', 'employees'));
    }

    public function store(Request $request)
    {
        $d = $request->validate(['employee_id' => 'required|integer|exists:hr_employees,id', 'asset_tag' => 'required|string|max:80', 'description' => 'required|string|max:500']);
        $item = app(EquipmentService::class)->issue($request->user(), HrEmployee::findOrFail($d['employee_id']), $d['asset_tag'], $d['description']);

        return $request->expectsJson() ? response()->json(['id' => $item->id], 201) : back()->with('success', 'Equipment assignment recorded.');
    }

    public function recordReturn(Request $request, HrEquipment $item)
    {
        $d = $request->validate(['condition' => 'required|string|max:2000', 'version' => 'required|integer|min:0']);
        $item = app(EquipmentService::class)->recordReturn($request->user(), $item, $d['condition'], (int) $d['version']);

        return $request->expectsJson() ? response()->json(['status' => $item->status]) : back()->with('success','Equipment return recorded.');
    }
}
