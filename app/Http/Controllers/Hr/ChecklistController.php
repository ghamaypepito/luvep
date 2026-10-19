<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrChecklist;
use App\Models\HrChecklistTask;
use App\Models\HrChecklistTemplate;
use App\Models\HrEmployee;
use App\Models\User;
use App\Services\Hr\ChecklistService;
use App\Services\Hr\HrAccess;
use App\Services\Hr\HrContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChecklistController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        $context = app(HrContext::class);
        $preview = $context->isPreview($request);
        $subject = $preview ? $context->subject($request) : app(HrAccess::class)->employee($actor);
        $manage = ! $preview && app(HrAccess::class)->allows($actor, 'operations.manage');
        $subjectUser = $subject?->user_id ?? $actor->id;
        $query = HrChecklist::with(['employee.user', 'tasks.owner']);
        if (! $manage) {
            $query->whereHas('tasks', fn ($q) => $q->where('owner_user_id', $subjectUser)->orWhere(fn ($q) => $q->where('employee_visible', true)->whereHas('checklist', fn ($q) => $q->where('hr_employee_id', $subject?->id ?? 0))));
        }
        $checklists = $query->latest('id')->limit(100)->get()->map(function ($c) use ($manage, $subject, $subjectUser, $actor, $preview) {
            $tasks = $c->tasks->filter(fn ($t) => $manage || $t->owner_user_id === $subjectUser || ($c->hr_employee_id === $subject?->id && $t->employee_visible))->map(function ($t) use ($manage, $actor, $preview) {
                $row = $t->only(['id', 'title', 'kind', 'note', 'version', 'completed_at']);
                $row['due_date'] = $t->due_date->toDateString();
                $row['owner_name'] = $t->owner?->name;
                $row['can_complete'] = ! $preview && app(ChecklistService::class)->canComplete($actor, $t);
                if ($manage) {
                    $row['private_note'] = $t->private_note;
                }

return $row;
            })->values();

            return ['id' => $c->id, 'name' => $c->name, 'kind' => $c->kind, 'employee_name' => $c->employee->user?->name, 'due_date' => $c->due_date->toDateString(), 'completed_at' => $c->completed_at, 'tasks' => $tasks];
        });
        $templates = $manage ? HrChecklistTemplate::latest('id')->get() : [];
        $employees = $manage ? HrEmployee::with('user')->where('status', '!=', 'archived')->get()->map(fn ($e) => ['id' => $e->id, 'name' => $e->user?->name]) : [];
        $owners = $manage ? User::where('is_active', true)->get()->filter(fn ($u) => app(ChecklistService::class)->eligibleOwner($u))->map(fn ($u) => $u->only(['id', 'name']))->values() : [];

        return Inertia::render('hr/Checklists', compact('checklists', 'templates', 'employees', 'owners', 'manage'));
    }

    public function template(Request $request, ?HrChecklistTemplate $template = null)
    {
        app(ChecklistService::class)->saveTemplate($request->user(), $request->all(), $template);

        return back()->with('success', 'Template saved. Active checklists retain their original tasks.');
    }

    public function start(Request $request)
    {
        $data = $request->validate(['employee_id' => 'required|integer|exists:hr_employees,id', 'template_id' => 'required|integer|exists:hr_checklist_templates,id', 'due_date' => 'required|date_format:Y-m-d']);
        $c = app(ChecklistService::class)->start($request->user(), HrEmployee::findOrFail($data['employee_id']), HrChecklistTemplate::findOrFail($data['template_id']), $data['due_date']);

        return $request->expectsJson() ? response()->json(['id' => $c->id], 201) : back()->with('success', 'Checklist started.');
    }

    public function complete(Request $request, HrChecklistTask $task)
    {
        $data = $request->validate(['version' => 'required|integer|min:0']);
        $task = app(ChecklistService::class)->complete($request->user(), $task, (int) $data['version']);

        return $request->expectsJson() ? response()->json(['completed_at' => $task->completed_at]) : back()->with('success','Task completed.');
    }
}
