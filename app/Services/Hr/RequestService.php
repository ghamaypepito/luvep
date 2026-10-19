<?php

namespace App\Services\Hr;

use App\Models\HrEmployee;
use App\Models\HrRequest;
use App\Models\HrRequestMessage;
use App\Models\User;
use App\Policies\HrRequestPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RequestService
{
    public function open(User $actor, string $category, string $subject, string $body, string $requestId): HrRequest
    {
        $employee = app(HrAccess::class)->employee($actor);
        abort_unless($employee, 403);
        Validator::make(compact('category', 'subject', 'body', 'requestId'), ['category' => 'required|in:employment_letter,contact_correction,general,confidential', 'subject' => 'required|string|max:200', 'body' => 'required|string|max:20000', 'requestId' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($actor, $employee, $category, $subject, $body, $requestId) {
            HrEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if ($existing = HrRequest::where('hr_employee_id', $employee->id)->where('request_id', $requestId)->first()) {
                return $existing;
            }$case = HrRequest::create(['hr_employee_id' => $employee->id, 'category' => $category, 'subject' => $subject, 'request_id' => $requestId]);
            $case->messages()->create(['actor_id' => $actor->id, 'body' => $body, 'internal' => false, 'created_at' => now()]);
            $this->event($actor, $case, 'opened');

            return $case;
        });
    }

    public function reply(User $actor, HrRequest $case, string $body, bool $internal): HrRequestMessage
    {
        $policy = app(HrRequestPolicy::class);
        abort_unless($policy->view($actor, $case), 404);
        abort_if($internal && ! $policy->manage($actor, $case), 403);
        Validator::make(compact('body'), ['body' => 'required|string|max:20000'])->validate();

        return DB::transaction(function () use ($actor, $case, $body, $internal, $policy) {
            $case = $this->lock($case);
            $actor = $actor->fresh();
            abort_unless($actor && $policy->view($actor, $case), 404);
            abort_if($internal && ! $policy->manage($actor, $case), 403);
            abort_unless($case->status !== 'closed', 409, 'This request is closed. Open a new request for further assistance.');
            $message = $case->messages()->create(['actor_id' => $actor->id, 'body' => $body, 'internal' => $internal, 'created_at' => now()]);
            $case->version++;
            if (! $internal && $policy->manage($actor, $case)) {
                $case->status = 'in_progress';
            }$case->save();
            $this->event($actor, $case, $internal ? 'internal_note' : 'replied');

            return $message;
        });
    }

    public function assign(User $actor, HrRequest $case, User $assignee, int $version): HrRequest
    {
        abort_unless(app(HrAccess::class)->allows($actor, 'cases.manage') && $actor->id !== $case->employee->user_id, 403);
        if ($assignee->id === $case->employee->user_id || ! app(HrRequestPolicy::class)->eligibleAssignee($assignee)) {
            throw ValidationException::withMessages(['assignee_id' => 'Choose an active reviewer with permission for private HR cases.']);
        }

        return DB::transaction(function () use ($actor, $case, $assignee, $version) {
            $case = $this->lock($case);
            abort_unless($case->version === $version && $case->status !== 'closed', 409, 'This request changed. Refresh before assigning.');
            $case->update(['assigned_user_id' => $assignee->id, 'status' => 'in_progress', 'version' => $version + 1]);
            $this->event($actor, $case, 'assigned');

            return $case->fresh();
        });
    }

    public function close(User $actor, HrRequest $case, int $version): HrRequest
    {
        abort_unless(app(HrRequestPolicy::class)->manage($actor, $case), 403);

        return DB::transaction(function () use ($actor, $case, $version) {
            $case = $this->lock($case);
            abort_unless($case->version === $version && $case->status !== 'closed', 409, 'This request changed. Refresh before closing.');
            $case->update(['status' => 'closed', 'version' => $version + 1]);
            $this->event($actor, $case, 'closed');

            return $case->fresh();
        });
    }

    private function lock(HrRequest $case): HrRequest
    {
        HrEmployee::whereKey($case->hr_employee_id)->lockForUpdate()->firstOrFail();

        return HrRequest::whereKey($case->id)->lockForUpdate()->firstOrFail();
    }

    private function event(User $actor, HrRequest $case, string $action): void
    {
        app(HrAudit::class)->record($actor, 'request.'.$action, $case, ['employee_id' => $case->hr_employee_id, 'version' => $case->version]);
        if ($action !== 'internal_note') {
            app(HrEvents::class)->record('request.'.$action, $case, $actor, 'request:'.$case->id.':'.$case->version.':'.$action);
        }
    }
}
