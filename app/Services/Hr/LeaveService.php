<?php

namespace App\Services\Hr;

use App\Models\DtrLeave;
use App\Models\HrEmployee;
use App\Models\HrLeaveEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    public function submit(User $actor, array $validated, string $requestId): DtrLeave
    {
        $employee = app(HrAccess::class)->employee($actor);
        abort_unless($employee, 403);
        $sick = ($validated['type'] ?? '') === 'Personal / Sick Leave';
        $annual = ($validated['type'] ?? '') === 'Annual Leave';
        $data = Validator::make([...$validated, 'request_id' => $requestId], [
            'request_id' => 'required|uuid', 'type' => ['required', Rule::in(DtrLeave::TYPES)], 'other_specify' => 'required_if:type,Other|nullable|string|max:255',
            'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date', 'half_day' => 'nullable|in:AM,PM,N/A',
            'reason' => 'nullable|string|max:2000', 'declaration' => 'required|accepted', 'sick_for' => [$sick ? 'required' : 'nullable', Rule::in(['self', 'partner', 'dependant'])],
            'notified_via' => [$sick ? 'required' : 'nullable', 'string', 'max:255'], 'notified_at' => [$sick ? 'required' : 'nullable', 'date', 'before_or_equal:now'],
            'certificate_status' => [$sick ? 'required' : 'nullable', Rule::in(['not_needed', 'will_provide', 'provided_to_hr'])],
            'team_checked' => [$annual ? 'accepted' : 'nullable', 'boolean'], 'travel_acknowledged' => [$annual ? 'accepted' : 'nullable', 'boolean'], 'auto_reply_planned' => [$annual ? 'accepted' : 'nullable', 'boolean'],
            'cover_person' => 'nullable|string|max:255', 'handover' => 'nullable|string|max:5000',
        ])->validate();

        return DB::transaction(function () use ($actor, $employee, $data, $annual) {
            HrEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if ($existing = DtrLeave::where('user_id', $actor->id)->where('request_id', $data['request_id'])->first()) {
                return $existing;
            }
            $calendar = app(WorkingCalendar::class);
            $snapshot = $calendar->duration($employee, $data['start_date'], $data['end_date'], $data['half_day'] ?? null);
            $overlaps = DtrLeave::where('user_id', $actor->id)->whereIn('status', ['pending', 'approved', 'deferred'])->whereDate('start_date', '<=', $data['end_date'])->whereDate('end_date', '>=', $data['start_date'])->get();
            foreach ($overlaps as $other) {
                $complementary = $data['start_date'] === $data['end_date'] && $other->start_date->toDateString() === $other->end_date->toDateString() && in_array($data['half_day'] ?? null, ['AM', 'PM'], true) && in_array($other->half_day, ['AM', 'PM'], true) && $data['half_day'] !== $other->half_day;
                if (! $complementary) {
                    throw ValidationException::withMessages(['dates' => 'This period overlaps an existing leave request.']);
                }
            }
            $notice = CarbonImmutable::instance(now($employee->schedule->timezone))->startOfDay()->diffInDays(CarbonImmutable::parse($data['start_date'], $employee->schedule->timezone), false);
            $leave = new DtrLeave;
            $leave->forceFill([...$data, 'user_id' => $actor->id, 'full_name' => $actor->name, 'position' => $employee->position, 'declared_name' => $actor->name, 'employee_signed_at' => now(), 'declaration' => true, 'total_days' => $snapshot['total_days'], 'calendar_snapshot' => $snapshot, 'response_due' => $calendar->responseDue($employee), 'emergency_required' => $annual && $notice < 14, 'status' => 'pending', 'hr_version' => 0])->save();
            $this->event($actor, $leave, 'submitted', '');

            return $leave->fresh();
        });
    }

    public function decide(User $actor, DtrLeave $leave, string $decision, string $comment, int $version): DtrLeave
    {
        $employee = HrEmployee::where('user_id', $leave->user_id)->firstOrFail();
        $access = app(HrAccess::class);
        abort_unless($access->allows($actor, 'leave.review', $employee) || $access->allows($actor, 'leave.fallback', $employee), 403);
        Validator::make(compact('decision', 'comment'), ['decision' => 'required|in:approved,rejected,deferred', 'comment' => [$decision === 'approved' ? 'nullable' : 'required', 'string', 'max:5000']])->validate();

        return $this->mutate($actor, $leave, $version, function ($leave) use ($actor, $decision, $comment) {
            if ($decision === 'approved' && $leave->emergency_required && ! $leave->emergency_approved_at) {
                throw ValidationException::withMessages(['emergency' => 'Management must approve the short-notice exception before the Department Head can approve this leave.']);
            }
            $leave->forceFill(['status' => $decision, 'decision' => ['approved' => 'Approved', 'rejected' => 'Declined', 'deferred' => 'Deferred'][$decision], 'manager_comments' => $comment, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);

            return [$decision, $comment];
        });
    }

    public function approveEmergency(User $actor, DtrLeave $leave, string $comment, int $version): DtrLeave
    {
        $employee = HrEmployee::where('user_id', $leave->user_id)->firstOrFail();
        abort_unless(app(HrAccess::class)->allows($actor, 'leave.emergency', $employee), 403);
        Validator::make(compact('comment'), ['comment' => 'required|string|max:5000'])->validate();

        return $this->mutate($actor, $leave, $version, function ($leave) use ($actor, $comment) {
            abort_unless($leave->emergency_required && ! $leave->emergency_approved_at, 409);
            $leave->forceFill(['emergency_approved_by' => $actor->id, 'emergency_approved_at' => now()]);

            return ['emergency_approved', $comment];
        });
    }

    public function cancel(User $actor, DtrLeave $leave, int $version): DtrLeave
    {
        abort_unless($leave->user_id === $actor->id && app(HrAccess::class)->employee($actor), 404);

        return $this->mutate($actor, $leave, $version, function ($leave) {
            $leave->status = 'cancelled';

            return ['cancelled', ''];
        });
    }

    private function mutate(User $actor, DtrLeave $leave, int $version, callable $change): DtrLeave
    {
        return DB::transaction(function () use ($actor, $leave, $version, $change) {
            HrEmployee::where('user_id', $leave->user_id)->lockForUpdate()->firstOrFail();
            $leave = DtrLeave::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            abort_unless($leave->hr_version === $version && in_array($leave->status, ['pending', 'deferred'], true), 409, 'This request changed. Refresh before continuing.');
            [$action,$comment] = $change($leave);
            $leave->hr_version++;
            $leave->save();
            $this->event($actor, $leave, $action, $comment);

            return $leave->fresh();
        });
    }

    private function event(User $actor, DtrLeave $leave, string $action, string $comment): void
    {
        HrLeaveEvent::create(['dtr_leave_id' => $leave->id, 'actor_id' => $actor->id, 'action' => $action, 'comment' => $comment, 'version' => $leave->hr_version, 'created_at' => now()]);
        app(HrAudit::class)->record($actor,'leave.'.$action,$leave,['version' => $leave->hr_version]);
        app(HrEvents::class)->record('leave.'.$action,$leave,$actor,'leave:'.$leave->id.':'.$leave->hr_version.':'.$action);
    }
}
