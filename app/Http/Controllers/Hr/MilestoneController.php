<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrEmployee;
use App\Models\HrMilestone;
use App\Models\User;
use App\Services\Hr\ChecklistService;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use App\Services\Hr\MilestoneService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MilestoneController extends Controller
{
    public function index(Request $request)
    {
        $context = app(HrContext::class);
        $preview = $context->isPreview($request);
        $actor = $request->user();
        $subject = $preview ? $context->subject($request)->user : $actor;
        $manage = ! $preview && app(HrAccess::class)->allows($actor, 'operations.manage');
        $service = app(MilestoneService::class);
        $milestones = $service->visible($subject, $manage)->with(['employee.user', 'owner'])->orderBy('due_date')->limit(200)->get()->map(function ($m) use ($manage, $actor, $preview, $service) {
            $row = $m->only(['id', 'hr_employee_id', 'kind', 'title', 'owner_user_id', 'employee_visible', 'version', 'completed_at']);
            $row['due_date'] = $m->due_date->toDateString();
            $row['employee_name'] = $m->employee->user?->name;
            $row['owner_name'] = $m->owner?->name;
            $row['can_complete'] = ! $preview && $service->canComplete($actor, $m);
            if ($manage) {
                $row['private_note'] = $m->private_note;
            }

return $row;
        });
        $employees = $manage ? HrEmployee::with('user')->where('status', '!=', 'archived')->get()->map(fn ($e) => ['id' => $e->id, 'name' => $e->user?->name]) : [];
        $owners = $manage ? User::where('is_active', true)->get()->filter(fn ($u) => app(ChecklistService::class)->eligibleOwner($u))->map(fn ($u) => $u->only(['id', 'name']))->values() : [];

        return Inertia::render('hr/Milestones', compact('milestones', 'employees', 'owners', 'manage'));
    }

    public function save(Request $request, ?HrMilestone $milestone = null)
    {
        $data = $request->validate(['employee_id' => 'required|integer|exists:hr_employees,id']);
        app(MilestoneService::class)->save($request->user(), HrEmployee::findOrFail($data['employee_id']), $request->all(), $milestone);

        return back()->with('success', 'Milestone saved.');
    }

    public function complete(Request $request, HrMilestone $milestone)
    {
        $data = $request->validate(['version' => 'required|integer|min:0']);
        app(MilestoneService::class)->complete($request->user(), $milestone, $data['version']);

        return back()->with('success','Milestone completed.');
    }
}
