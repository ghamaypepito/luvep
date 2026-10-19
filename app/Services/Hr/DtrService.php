<?php

namespace App\Services\Hr;

use App\Models\DtrEntry;
use App\Models\DtrSetting;
use App\Models\HrDtrReview;
use App\Models\HrEmployee;
use App\Models\HrShift;
use App\Models\HrWeeklySignoff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DtrService
{
    public function row(DtrEntry $entry): array
    {
        $setting = DtrSetting::where('user_id', $entry->user_id)->first();
        $seconds = 0;
        $break = 0;
        if ($entry->time_source === 'live') {
            $shifts = HrShift::with('breaks')->whereHas('employee', fn ($q) => $q->where('user_id', $entry->user_id))->whereDate('work_date', $entry->work_date)->get();
            foreach ($shifts as $shift) {
                $totals = app(ClockService::class)->totals($shift, CarbonImmutable::instance(now('UTC')));
                $seconds += $totals['working_seconds'];
                $break += $totals['break_seconds'];
            }
        } elseif ($entry->time_in && $entry->time_out) {
            $from = $this->minutes($entry->time_in);
            $to = $this->minutes($entry->time_out);
            if ($to <= $from) {
                $to += 1440;
            }
            $elapsed = ($to - $from) * 60;
            $break = $elapsed >= (float) ($setting?->break_after ?? 6) * 3600 ? (int) round((float) ($setting?->break_hours ?? 1) * 3600) : 0;
            $seconds = max(0, $elapsed - $break);
        }

        return [...$entry->only(['id', 'time_in', 'time_out', 'tasks', 'remarks', 'hr_status', 'hr_version', 'work_location', 'meetings', 'people_assisted', 'blockers', 'tomorrow', 'declared_name', 'submitted_at']),
            'can_edit' => $this->canEditDraft($entry),
            'review_comment' => HrDtrReview::where('dtr_entry_id', $entry->id)->latest('id')->value('comment'),
            'work_date' => $entry->work_date->toDateString(), 'working_seconds' => $seconds, 'break_seconds' => $break,
            'task_seconds' => (int) collect($entry->tasks ?? [])->sum('seconds'), 'time_source' => $entry->time_source];
    }

    public function canEditDraft(DtrEntry $entry): bool
    {
        if (! in_array($entry->hr_status, ['draft', 'returned'], true)) {
            return false;
        }
        if ($entry->hr_status === 'returned') {
            return true;
        }
        $setting = DtrSetting::where('user_id', $entry->user_id)->first();
        $localNow = CarbonImmutable::instance(now($setting?->timezone ?? 'UTC'));
        if ($entry->work_date->toDateString() === $localNow->toDateString()) {
            return true;
        }

        return $entry->time_source === 'live' && HrShift::whereHas('employee', fn ($q) => $q->where('user_id', $entry->user_id))->whereDate('work_date', $entry->work_date)->where(fn ($q) => $q->whereNull('ended_at')->orWhereBetween('ended_at', [$localNow->startOfDay()->utc(), $localNow->utc()]))->exists();
    }

    public function saveDraft(HrEmployee $employee, array $validated): DtrEntry
    {
        abort_unless($employee->user && app(HrAccess::class)->employee($employee->user)?->id === $employee->id, 403);
        $data = Validator::make($validated, [
            'work_date' => ['required', 'date_format:Y-m-d'], 'expected_version' => ['required', 'integer', 'min:0'],
            'time_in' => ['prohibited'], 'time_out' => ['prohibited'],
            'work_location' => ['nullable', 'string', 'max:120'], 'tasks' => ['present', 'array', 'max:200'],
            'tasks.*.task' => ['required', 'string', 'max:2000'], 'tasks.*.status' => ['required', Rule::in(['done', 'in_progress', 'carried_over', 'todo'])],
            'tasks.*.category' => ['nullable', Rule::in(['client', 'admin', 'meeting', 'training', 'other'])], 'tasks.*.reference' => ['nullable', 'string', 'max:250'],
            'tasks.*.start' => ['nullable', 'date_format:H:i,H:i:s'], 'tasks.*.end' => ['nullable', 'date_format:H:i,H:i:s'],
            'meetings' => ['nullable', 'integer', 'min:0', 'max:10000'], 'people_assisted' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'blockers' => ['nullable', 'string', 'max:10000'], 'tomorrow' => ['nullable', 'string', 'max:10000'], 'remarks' => ['nullable', 'string', 'max:10000'],
        ])->validate();

        return DB::transaction(function () use ($employee, $data) {
            HrEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $entry = DtrEntry::where('user_id', $employee->user_id)->whereDate('work_date', $data['work_date'])->first() ?? new DtrEntry(['user_id' => $employee->user_id, 'work_date' => $data['work_date']]);
            abort_unless((int) $entry->hr_version === (int) $data['expected_version'], 409, 'This record changed. Refresh before saving.');
            abort_unless(! $entry->exists || in_array($entry->hr_status, ['draft', 'returned'], true), 409, 'This record is locked. Request a reviewed correction.');
            $today = now($employee->schedule?->timezone ?? 'UTC')->toDateString();
            if ($entry->exists && ! $this->canEditDraft($entry) || ! $entry->exists && $data['work_date'] !== $today) {
                throw ValidationException::withMessages(['work_date' => 'Only current-day records, the current overnight shift or returned records can be edited.']);
            }
            $data['tasks'] = $this->tasks($entry, $data['tasks']);
            unset($data['expected_version'], $data['work_date']);
            $entry->forceFill([...$data, 'hr_status' => $entry->hr_status === 'returned' ? 'returned' : 'draft', 'hr_version' => (int) $entry->hr_version + 1])->save();
            $this->invalidateWeek($employee, $entry);

            return $entry->fresh();
        });
    }

    public function submit(User $actor, DtrEntry $entry, int $version): DtrEntry
    {
        $employee = HrEmployee::where('user_id', $entry->user_id)->firstOrFail();
        abort_unless($actor->id === $entry->user_id && app(HrAccess::class)->employee($actor)?->id === $employee->id, 404);

        return $this->mutate($employee, $entry, $version, function ($entry) use ($actor) {
            abort_unless(in_array($entry->hr_status, ['draft', 'returned'], true), 409, 'The record is already submitted.');
            if (! $entry->time_out || ! $entry->work_location || empty($entry->tasks)) {
                throw ValidationException::withMessages(['dtr' => 'Clock out, add your work location and complete your tasks before submitting.']);
            }
            $entry->tasks = $this->tasks($entry, $entry->tasks, true);
            $entry->forceFill(['hr_status' => 'submitted', 'declared_name' => $actor->name, 'submitted_at' => now()]);

            return ['event' => 'dtr.submitted', 'actor' => $actor];
        });
    }

    public function review(User $actor, DtrEntry $entry, string $decision, string $comment, int $version): DtrEntry
    {
        $employee = HrEmployee::where('user_id', $entry->user_id)->firstOrFail();
        abort_unless(app(HrAccess::class)->allows($actor, 'dtr.review', $employee), 403);
        Validator::make(compact('decision', 'comment'), ['decision' => ['required', Rule::in(['reviewed', 'returned'])], 'comment' => [$decision === 'returned' ? 'required' : 'nullable', 'string', 'max:5000']])->validate();

        return $this->mutate($employee, $entry, $version, function ($entry) use ($actor, $decision, $comment) {
            abort_unless($entry->hr_status === 'submitted', 409, 'Only a submitted record can be reviewed.');
            HrDtrReview::create(['dtr_entry_id' => $entry->id, 'actor_id' => $actor->id, 'decision' => $decision, 'comment' => $comment, 'version' => $entry->hr_version, 'created_at' => now()]);
            $entry->hr_status = $decision;

            return ['event' => 'dtr.'.$decision, 'actor' => $actor];
        });
    }

    private function mutate(HrEmployee $employee, DtrEntry $entry, int $version, callable $change): DtrEntry
    {
        return DB::transaction(function () use ($employee, $entry, $version, $change) {
            HrEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $entry = DtrEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $entry->hr_version === $version, 409, 'This record changed. Refresh and try again.');
            $result = $change($entry);
            $entry->hr_version++;
            $entry->save();
            $this->invalidateWeek($employee, $entry);
            app(HrAudit::class)->record($result['actor'], $result['event'], $entry, ['employee_id' => $employee->id, 'version' => $entry->hr_version]);
            app(HrEvents::class)->record($result['event'], $entry, $result['actor'], 'dtr:'.$entry->id.':'.$entry->hr_version.':'.$result['event']);

            return $entry->fresh();
        });
    }

    private function invalidateWeek(HrEmployee $employee, DtrEntry $entry): void
    {
        HrWeeklySignoff::where('hr_employee_id', $employee->id)->whereDate('week_start', $entry->work_date->copy()->startOfWeek()->toDateString())->update(['status' => 'stale']);
    }

    public function signWeek(User $actor, HrEmployee $employee, string $weekStart): HrWeeklySignoff
    {
        abort_unless($actor->id === $employee->user_id && app(HrAccess::class)->employee($actor)?->id === $employee->id, 404);
        Validator::make(['week' => $weekStart], ['week' => ['required', 'date_format:Y-m-d']])->validate();
        $start = CarbonImmutable::parse($weekStart, $employee->schedule?->timezone ?? 'UTC');
        if ($start->dayOfWeekIso !== 1) {
            throw ValidationException::withMessages(['week' => 'Select the Monday beginning the week.']);
        }

        return DB::transaction(function () use ($actor, $employee, $start) {
            HrEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $setting = $employee->schedule;
            if (! $setting?->is_complete) {
                throw ValidationException::withMessages(['week' => 'HR must complete your schedule first.']);
            }
            $days = ! empty($setting->weekly_schedule['sun']['on']) ? 7 : 6;
            $revisions = [];
            if ($start->addDays($days - 1)->toDateString() > now($setting->timezone)->toDateString()) {
                throw ValidationException::withMessages(['week' => 'The working week is not complete yet.']);
            }
            for ($i = 0; $i < $days; $i++) {
                $date = $start->addDays($i)->toDateString();
                if (! app(WorkingCalendar::class)->day($employee, $date)['working']) {
                    continue;
                }
                $entry = DtrEntry::where('user_id', $employee->user_id)->whereDate('work_date', $date)->first();
                $fullLeave = \App\Models\DtrLeave::where('user_id', $employee->user_id)->where('status', 'approved')->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->where(fn ($q) => $q->whereNull('half_day')->orWhere('half_day', 'N/A'))->exists();
                if (! $entry && $fullLeave) {
                    continue;
                }
                if (! $entry || $entry->hr_status !== 'reviewed') {
                    throw ValidationException::withMessages(['week' => 'Every scheduled day needs a reviewed DTR before weekly sign-off.']);
                }
                $revisions[$entry->id] = $entry->hr_version;
            }
            if (! $revisions) {
                throw ValidationException::withMessages(['week' => 'There are no reviewed working days to sign.']);
            }
            $signoff = HrWeeklySignoff::firstOrNew(['hr_employee_id' => $employee->id, 'week_start' => $start->toDateString()]);
            if ($signoff->exists && in_array($signoff->status, ['submitted', 'reviewed'], true) && $signoff->revisions === $revisions) {
                return $signoff;
            }
            $signoff->forceFill(['revisions' => $revisions, 'status' => 'submitted', 'version' => (int) $signoff->version + 1, 'signed_by' => $actor->id, 'signed_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null])->save();
            app(HrEvents::class)->record('dtr.week_submitted', $signoff, $actor, 'week:'.$signoff->id.':'.$signoff->version);

            return $signoff;
        });
    }

    public function reviewWeek(User $actor, HrWeeklySignoff $week, string $decision, string $comment, int $version): HrWeeklySignoff
    {
        $employee = $week->employee;
        abort_unless(app(HrAccess::class)->allows($actor, 'dtr.review', $employee), 403);
        Validator::make(compact('decision', 'comment'), ['decision' => ['required', Rule::in(['reviewed', 'returned'])], 'comment' => [$decision === 'returned' ? 'required' : 'nullable', 'string', 'max:5000']])->validate();

        return DB::transaction(function () use ($employee, $week, $actor, $decision, $comment, $version) {
            HrEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $week = HrWeeklySignoff::whereKey($week->id)->lockForUpdate()->firstOrFail();
            abort_unless($week->version === $version && $week->status === 'submitted', 409, 'The weekly record changed. Refresh and try again.');
            foreach ($week->revisions as $id => $revision) {
                $entry = DtrEntry::find($id);
                abort_unless($entry && $entry->hr_status === 'reviewed' && $entry->hr_version === $revision, 409, 'A daily record changed. The employee must sign the week again.');
            }
            $week->update(['status' => $decision, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_comment' => $comment, 'version' => $version + 1]);
            app(HrAudit::class)->record($actor, 'dtr.week_'.$decision, $week, ['employee_id' => $employee->id, 'version' => $week->version]);
            app(HrEvents::class)->record('dtr.week_'.$decision, $week, $actor, 'week:'.$week->id.':'.$week->version.':'.$decision);

            return $week->fresh();
        });
    }

    public function correct(User $actor, DtrEntry $entry, array $validatedIntervals, string $reason, int $version): DtrEntry
    {
        $employee = HrEmployee::where('user_id', $entry->user_id)->firstOrFail();
        abort_unless(app(HrAccess::class)->allows($actor, 'dtr.correct', $employee), 403);
        Validator::make(['reason' => $reason, 'intervals' => $validatedIntervals], ['reason' => ['required', 'string', 'max:5000'], 'intervals' => ['required', 'array', 'min:1']])->validate();

        return $this->mutate($employee, $entry, $version, function ($entry) use ($actor, $employee, $validatedIntervals, $reason) {
            $shifts = HrShift::with('breaks')->where('hr_employee_id', $employee->id)->whereDate('work_date', $entry->work_date)->get();
            abort_if($shifts->isEmpty() || $shifts->contains(fn ($s) => ! $s->ended_at), 409, 'Only closed live shifts can be corrected here.');
            $ids = collect($validatedIntervals)->pluck('shift_id')->sort()->values()->all();
            abort_unless($ids === $shifts->pluck('id')->sort()->values()->all(), 422, 'Include each shift exactly once.');
            $before = $shifts->toArray();
            $lastEnd = null;
            $intervals = collect($validatedIntervals)->sortBy('started_at');
            foreach ($intervals as $interval) {
                $v = Validator::make($interval, ['shift_id' => ['required', 'integer'], 'started_at' => ['required', 'date'], 'ended_at' => ['required', 'date', 'after:started_at'], 'breaks' => ['present', 'array'], 'breaks.*.started_at' => ['required', 'date'], 'breaks.*.ended_at' => ['required', 'date', 'after:breaks.*.started_at']])->validate();
                $from = CarbonImmutable::parse($v['started_at'])->utc();
                $to = CarbonImmutable::parse($v['ended_at'])->utc();
                if ($to->isFuture() || ($lastEnd && $from->lessThan($lastEnd))) {
                    throw ValidationException::withMessages(['intervals' => 'Shifts must not overlap or end in the future.']);
                }
                $shift = $shifts->firstWhere('id', $v['shift_id']);
                if ($from->setTimezone($shift->timezone)->toDateString() !== $entry->work_date->toDateString()) {
                    throw ValidationException::withMessages(['intervals' => 'Keep the shift on its original work date.']);
                }
                $lastEnd = $to;
                $breakEnd = $from;
                foreach (collect($v['breaks'])->sortBy('started_at') as $b) {
                    $bfrom = CarbonImmutable::parse($b['started_at'])->utc();
                    $bto = CarbonImmutable::parse($b['ended_at'])->utc();
                    if ($bfrom->lessThan($breakEnd) || $bto->greaterThan($to) || $bto->lessThanOrEqualTo($bfrom)) {
                        throw ValidationException::withMessages(['intervals' => 'Breaks must fit the shift without overlap.']);
                    }
                    $breakEnd = $bto;
                }
                $shift->update(['started_at' => $from, 'ended_at' => $to]);
                $shift->breaks()->delete();
                foreach ($v['breaks'] as $b) {
                    $shift->breaks()->create(['started_at' => CarbonImmutable::parse($b['started_at'])->utc(), 'ended_at' => CarbonImmutable::parse($b['ended_at'])->utc(), 'active_guard' => null]);
                }
            }
            $after = HrShift::with('breaks')->whereIn('id', $shifts->pluck('id'))->orderBy('started_at')->get();
            $entry->forceFill(['time_in' => $after->first()->started_at->setTimezone($after->first()->timezone)->format('H:i'), 'time_out' => $after->last()->ended_at->setTimezone($after->last()->timezone)->format('H:i'), 'hr_status' => 'returned']);
            HrDtrReview::create(['dtr_entry_id' => $entry->id, 'actor_id' => $actor->id, 'decision' => 'corrected', 'comment' => $reason, 'version' => $entry->hr_version, 'before_state' => $before, 'after_state' => $after->toArray(), 'created_at' => now()]);

            return ['event' => 'dtr.corrected', 'actor' => $actor];
        });
    }

    private function tasks(DtrEntry $entry, array $tasks, bool $submitting = false): array
    {
        $setting = DtrSetting::where('user_id', $entry->user_id)->first();
        $timezone = $setting?->timezone ?? 'UTC';
        $shifts = HrShift::with('breaks')->whereHas('employee', fn ($q) => $q->where('user_id', $entry->user_id))->whereDate('work_date', $entry->work_date)->get();
        $intervals = [];
        foreach ($tasks as &$task) {
            $task['seconds'] = 0;
            if (empty($task['start']) && empty($task['end']) && (! $submitting || in_array($task['status'], ['todo', 'carried_over'], true))) {
                continue;
            }
            if (empty($task['start']) || empty($task['end'])) {
                throw ValidationException::withMessages(['tasks' => 'Enter actual start and end times for work performed.']);
            }
            $fits = false;
            foreach ($shifts as $shift) {
                // Resolve calendar rollover in the recorded shift timezone before converting to UTC.
                $zone = $shift->timezone;
                $date = $entry->work_date->toDateString();
                $localFrom = CarbonImmutable::parse($date.' '.$task['start'], $zone);
                $end = $shift->ended_at ?? CarbonImmutable::instance(now('UTC'));
                if ($localFrom->utc()->startOfMinute()->lessThan($shift->started_at->startOfMinute()) && $shift->started_at->setTimezone($zone)->toDateString() !== $end->setTimezone($zone)->toDateString()) {
                    $localFrom = CarbonImmutable::parse($localFrom->addDay()->toDateString().' '.$task['start'], $zone);
                }
                $localTo = CarbonImmutable::parse($localFrom->toDateString().' '.$task['end'], $zone);
                if ($localTo->lessThanOrEqualTo($localFrom)) {
                    $localTo = CarbonImmutable::parse($localFrom->addDay()->toDateString().' '.$task['end'], $zone);
                }
                $segments = [];
                $cursor = $shift->started_at;
                foreach ($shift->breaks->sortBy('started_at') as $pause) {
                    $breakStart = $pause->started_at->min($end);
                    $breakEnd = ($pause->ended_at ?? $end)->min($end);
                    if ($breakStart->greaterThan($cursor)) {
                        $segments[] = [$cursor, $breakStart];
                    }
                    $cursor = $cursor->max($breakEnd);
                }
                if ($cursor->lessThan($end)) {
                    $segments[] = [$cursor, $end];
                }
                foreach ($segments as [$segmentStart, $segmentEnd]) {
                    $f = $localFrom->utc();
                    $t = $localTo->utc();
                    // Minute entries at a recorded boundary use its exact instant; never bridge a break.
                    if (strlen($task['start']) === 5 && $f->startOfMinute()->equalTo($segmentStart->startOfMinute())) {
                        $f = $segmentStart;
                    }
                    if (strlen($task['end']) === 5 && $t->startOfMinute()->equalTo($segmentEnd->startOfMinute())) {
                        $t = $segmentEnd;
                    }
                    if ($f->greaterThanOrEqualTo($segmentStart) && $t->lessThanOrEqualTo($segmentEnd) && $t->greaterThan($f)) {
                        $fits = true;
                        $from = $f;
                        $to = $t;
                        break 2;
                    }
                }
            }
            if (! $fits) {
                throw ValidationException::withMessages(['tasks' => 'Task times must fit recorded work and cannot overlap breaks.']);
            }
            foreach ($intervals as [$a,$b]) {
                if ($from->lessThan($b) && $to->greaterThan($a)) {
                    throw ValidationException::withMessages(['tasks' => 'Task times cannot overlap each other.']);
                }
            }
            $intervals[] = [$from, $to];
            $task['seconds'] = $to->getTimestamp() - $from->getTimestamp();
        }
        unset($task);

        return $tasks;
    }

    private function minutes(string $time): int
    {
        [$h,$m] = explode(':', $time);

        return (int) $h * 60 + (int) $m;
    }
}
