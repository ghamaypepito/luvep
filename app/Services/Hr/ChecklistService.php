<?php

namespace App\Services\Hr;

use App\Models\HrChecklist;
use App\Models\HrChecklistTask;
use App\Models\HrChecklistTemplate;
use App\Models\HrEmployee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ChecklistService
{
    public function saveTemplate(User $actor, array $data, ?HrChecklistTemplate $template = null): HrChecklistTemplate
    {
        abort_unless(app(HrAccess::class)->allows($actor, 'operations.manage'), 403);
        $data = Validator::make($data, ['name' => 'required|string|max:200', 'kind' => 'required|in:onboarding,exit', 'version' => 'required|integer|min:0', 'tasks' => 'required|array|min:1|max:50', 'tasks.*' => 'array:title,kind,owner_user_id,employee_visible,note,private_note', 'tasks.*.title' => 'required|string|max:200', 'tasks.*.kind' => 'required|in:general,equipment_issue,equipment_return,access_removal', 'tasks.*.owner_user_id' => 'nullable|integer', 'tasks.*.employee_visible' => 'required|boolean', 'tasks.*.note' => 'nullable|string|max:5000', 'tasks.*.private_note' => 'nullable|string|max:5000'])->validate();
        foreach ($data['tasks'] as $task) {
            if (! empty($task['owner_user_id'])) {
                $owner = User::find($task['owner_user_id']);
                if (! $owner || ! $this->eligibleOwner($owner)) {
                    throw ValidationException::withMessages(['tasks' => 'Task owners must be active internal employees or administrators.']);
                }
            }
        }

        return DB::transaction(function () use ($actor, $data, $template) {
            $current = $template ? HrChecklistTemplate::whereKey($template->id)->lockForUpdate()->firstOrFail() : new HrChecklistTemplate;
            abort_unless($current->version === (int) $data['version'], 409, 'The template changed. Refresh before saving.');
            $current->fill([...$data, 'version' => $data['version'] + 1])->save();
            app(HrAudit::class)->record($actor, 'checklist.template_saved', $current, ['version' => $current->version]);

            return $current;
        });
    }

    public function eligibleOwner(User $user): bool
    {
        return app(HrAccess::class)->internal($user) && ($user->isAdmin() || app(HrAccess::class)->employee($user));
    }

    public function start(User $actor, HrEmployee $employee, HrChecklistTemplate $template, string $dueDate): HrChecklist
    {
        abort_unless(app(HrAccess::class)->allows($actor, 'operations.manage'), 403);
        Validator::make(['due' => $dueDate], ['due' => 'required|date_format:Y-m-d'])->validate();

        return DB::transaction(function () use ($actor, $employee, $template, $dueDate) {
            HrEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            abort_if($employee->fresh()->status === 'archived', 409, 'Archived employees cannot start a new checklist.');
            $template = HrChecklistTemplate::whereKey($template->id)->lockForUpdate()->firstOrFail();
            abort_if(HrChecklist::where('hr_employee_id', $employee->id)->where('kind', $template->kind)->whereNull('completed_at')->exists(), 409, 'This employee already has an open checklist of this type.');
            $checklist = HrChecklist::create(['hr_employee_id' => $employee->id, 'hr_checklist_template_id' => $template->id, 'name' => $template->name, 'kind' => $template->kind, 'template_snapshot' => $template->tasks, 'due_date' => $dueDate, 'created_by' => $actor->id]);
            foreach ($template->tasks as $task) {
                $ownerId = $task['owner_user_id'] ?? $employee->user_id;
                $owner = User::find($ownerId);
                if (! $owner || ! app(HrAccess::class)->internal($owner) || ($ownerId !== $employee->user_id && ! $this->eligibleOwner($owner))) {
                    throw ValidationException::withMessages(['template_id' => 'A task owner is no longer eligible. Update the template first.']);
                }$created = $checklist->tasks()->create([...array_intersect_key($task, array_flip(['title', 'kind', 'employee_visible', 'note', 'private_note'])), 'owner_user_id' => $ownerId, 'due_date' => $dueDate]);
                app(HrEvents::class)->record('checklist.assigned', $created, $actor, 'checklist-task:'.$created->id.':assigned');
            }
            app(HrAudit::class)->record($actor, 'checklist.started', $checklist, ['employee_id' => $employee->id]);

            return $checklist->fresh('tasks');
        });
    }

    public function canComplete(User $actor, HrChecklistTask $task): bool
    {
        return app(HrAccess::class)->allows($actor, 'operations.manage') || ($task->owner_user_id === $actor->id && $this->eligibleOwner($actor));
    }

    public function complete(User $actor, HrChecklistTask $task, int $version): HrChecklistTask
    {
        abort_unless($this->canComplete($actor, $task), 403);

        return DB::transaction(function () use ($actor, $task, $version) {
            $checklist = HrChecklist::whereKey($task->hr_checklist_id)->lockForUpdate()->firstOrFail();
            $task = HrChecklistTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            if ($task->completed_at) {
                return $task;
            }abort_unless($task->version === $version, 409, 'This task changed. Refresh and retry.');
            $task->update(['completed_at' => now(), 'completed_by' => $actor->id, 'version' => $version + 1]);
            if (! $checklist->tasks()->whereNull('completed_at')->exists()) {
                $checklist->update(['completed_at' => now(), 'active_guard' => null]);
            }app(HrAudit::class)->record($actor, 'checklist.task_completed', $task, ['employee_id' => $checklist->hr_employee_id, 'version' => $task->version]);
            app(HrEvents::class)->record('checklist.completed',$task,$actor,'checklist-task:'.$task->id.':completed');

            return $task;
        });
    }
}
