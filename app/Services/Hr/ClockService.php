<?php

namespace App\Services\Hr;

use App\Models\DtrEntry;
use App\Models\HrEmployee;
use App\Models\HrAuditEvent;
use App\Models\HrShift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClockService
{
    public const MAX_WORKING_SECONDS = 36000;

    private function breakIntervals(HrShift $shift, CarbonImmutable $end): Collection
    {
        $start = $shift->started_at->getTimestamp();
        $finish = $end->getTimestamp();

        return $shift->breaks->map(fn ($b) => [max($start, $b->started_at->getTimestamp()), min($finish, ($b->ended_at ?? $end)->getTimestamp())])
            ->filter(fn ($b) => $b[1] > $b[0])->sortBy(fn ($b) => $b[0]);
    }

    private function automaticClockOutAt(HrShift $shift, CarbonImmutable $now): ?CarbonImmutable
    {
        $remaining = $shift->schedule_snapshot['max_working_seconds'] ?? null;
        if ($shift->ended_at || $remaining !== self::MAX_WORKING_SECONDS) {
            return null;
        }
        $cursor = $shift->started_at->getTimestamp();
        foreach ($this->breakIntervals($shift, $now) as [$from, $to]) {
            $work = max(0, $from - $cursor);
            if ($work >= $remaining) {
                return CarbonImmutable::createFromTimestamp($cursor + $remaining, 'UTC');
            }
            $remaining -= $work;
            $cursor = max($cursor, $to);
        }

        return $now->getTimestamp() - $cursor >= $remaining
            ? CarbonImmutable::createFromTimestamp($cursor + $remaining, 'UTC') : null;
    }

    public function totals(HrShift $shift, CarbonImmutable $now): array
    {
        $start = $shift->started_at->getTimestamp();
        $finish = $shift->ended_at ?? $this->automaticClockOutAt($shift, $now) ?? $now;
        $end = $finish->getTimestamp();
        $intervals = $this->breakIntervals($shift, $finish);
        $break = 0;
        $lastEnd = $start;
        foreach ($intervals as [$from, $to]) {
            $break += max(0, $to - max($from, $lastEnd));
            $lastEnd = max($lastEnd, $to);
        }

        return ['working_seconds' => max(0, $end - $start - $break), 'break_seconds' => $break];
    }

    public function state(HrEmployee $employee): array
    {
        $now = CarbonImmutable::instance(now('UTC'));
        $fresh = $employee->fresh();
        $shift = HrShift::with('breaks')->where('hr_employee_id', $employee->id)->latest('id')->first();
        $today = $now->setTimezone($employee->schedule?->timezone ?? 'UTC')->toDateString();
        if ($shift?->ended_at && $shift->work_date !== $today && $shift->ended_at->setTimezone($shift->timezone)->toDateString() !== $today) {
            $shift = null;
        }
        $completedToday = $shift?->ended_at !== null && $shift->work_date === $today;
        $break = $shift?->breaks->first(fn ($b) => $b->ended_at === null);

        return ['status' => ! $shift || $shift->ended_at ? 'off_shift' : ($break ? 'on_break' : 'working'),
            'reminder_claimed' => $shift && DB::table('hr_shift_reminders')->where('hr_shift_id', $shift->id)->exists(),
            'can_clock_in' => ! $completedToday && (! $shift || $shift->ended_at), 'completed_today' => $completedToday,
            'version' => (int) $fresh->clock_version, 'shift_id' => $shift?->id, 'work_date' => $shift?->work_date,
            'started_at' => $shift?->started_at?->toISOString(), 'ended_at' => $shift?->ended_at?->toISOString(),
            'break_started_at' => $break?->started_at?->toISOString(), 'server_now' => $now->toISOString(),
            'scheduled_end_at' => $shift?->scheduled_end_at?->toISOString(), 'timezone' => $shift?->timezone ?? $employee->schedule?->timezone,
            'max_working_seconds' => $shift?->schedule_snapshot['max_working_seconds'] ?? null,
            'automatically_clocked_out' => $shift?->ended_at && HrAuditEvent::where('action', 'clock.auto_clock_out')
                ->where('subject_type', $shift->getMorphClass())->where('subject_id', $shift->id)
                ->where('metadata->ended_at', $shift->ended_at->toISOString())->exists(),
            ...($shift ? $this->totals($shift, $now) : ['working_seconds' => 0, 'break_seconds' => 0])];
    }

    public function autoClockOut(int $employeeId, bool $dryRun = false): bool
    {
        if (! config('hr.enabled')) {
            return false;
        }

        return DB::transaction(function () use ($employeeId, $dryRun) {
            $employee = HrEmployee::whereKey($employeeId)->lockForUpdate()->first();
            $shift = $employee ? HrShift::with('breaks')->where('hr_employee_id', $employee->id)->whereNull('ended_at')->first() : null;

            return $shift && $this->closeAtWorkLimit($employee, $shift, CarbonImmutable::instance(now('UTC')), $dryRun);
        }, 3);
    }

    private function closeAtWorkLimit(HrEmployee $employee, HrShift $shift, CarbonImmutable $now, bool $dryRun = false): bool
    {
        $end = $this->automaticClockOutAt($shift, $now);
        if (! $end || $dryRun) {
            return $end !== null;
        }
        foreach ($shift->breaks->whereNull('ended_at') as $break) {
            $break->update(['ended_at' => $end->max($break->started_at), 'active_guard' => null]);
        }
        $shift->update(['ended_at' => $end, 'active_guard' => null]);
        DtrEntry::where('user_id', $employee->user_id)->whereDate('work_date', $shift->work_date)->where('time_source', 'live')
            ->update(['time_out' => $end->setTimezone($shift->timezone)->format('H:i')]);
        $employee->forceFill(['clock_version' => (int) $employee->clock_version + 1])->save();
        app(HrAudit::class)->record(null, 'clock.auto_clock_out', $shift, ['employee_id' => $employee->id,
            'version' => $employee->clock_version, 'ended_at' => $end->toISOString(), 'observed_at' => $now->toISOString()]);

        return true;
    }

    public function transition(HrEmployee $employee, string $action, string $requestId, int $expectedVersion): HrShift
    {
        abort_unless(in_array($action, ['clock_in', 'break_out', 'break_in', 'clock_out'], true), 422);

        $automaticallyClosed = false;
        $result = DB::transaction(function () use ($employee, $action, $requestId, $expectedVersion, &$automaticallyClosed) {
            $employee = HrEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            abort_unless($employee->user && app(HrAccess::class)->employee($employee->user)?->id === $employee->id, 403);
            $prior = DB::table('hr_clock_requests')->where('hr_employee_id', $employee->id)->where('request_id', $requestId)->first();
            if ($prior) {
                abort_unless($prior->action === $action, 409, 'This action key was already used. Refresh your clock.');

                return HrShift::findOrFail($prior->hr_shift_id);
            }
            abort_unless((int) $employee->clock_version === $expectedVersion, 409, 'Your clock changed elsewhere. Refresh and try again.');
            $now = CarbonImmutable::instance(now('UTC'));
            $shift = HrShift::with('breaks')->where('hr_employee_id', $employee->id)->whereNull('ended_at')->first();
            if ($shift && $this->closeAtWorkLimit($employee, $shift, $now)) {
                $automaticallyClosed = true;
                if ($action === 'clock_out') {
                    DB::table('hr_clock_requests')->insert(['hr_employee_id' => $employee->id, 'request_id' => $requestId, 'action' => $action, 'hr_shift_id' => $shift->id, 'created_at' => $now]);
                }

                return $shift->fresh('breaks');
            }
            if ($action === 'clock_in') {
                abort_if($shift, 409, 'A shift is already open.');
                $setting = $employee->schedule;
                if (! $setting?->is_complete || ! in_array($setting->timezone, timezone_identifiers_list(), true)) {
                    throw ValidationException::withMessages(['clock' => 'Ask HR to complete your schedule before clocking in.']);
                }
                $local = $now->setTimezone($setting->timezone);
                abort_if(HrShift::where('hr_employee_id', $employee->id)->whereDate('work_date', $local->toDateString())->whereNotNull('ended_at')->exists(), 409, 'This workday is complete. Ask an authorized reviewer for a clock correction.');
                $day = $setting->scheduleForDate($local->toDateString());
                $finish = null;
                if ($setting->schedule_type !== 'flexi' && $day['working'] && $day['out']) {
                    $finish = CarbonImmutable::parse($local->toDateString().' '.$day['out'], $setting->timezone);
                    if ($day['in'] && $day['out'] <= $day['in']) {
                        $finish = $finish->addDay();
                    }
                }
                $entry = DtrEntry::where('user_id', $employee->user_id)->whereDate('work_date', $local->toDateString())->first()
                    ?? new DtrEntry(['user_id' => $employee->user_id, 'work_date' => $local->toDateString()]);
                if ($entry->exists && $entry->time_source !== 'live' && $entry->time_in) {
                    throw ValidationException::withMessages(['clock' => 'A manual time record already exists today. Ask HR to review it before starting a live shift.']);
                }
                abort_unless(! $entry->exists || in_array($entry->hr_status, ['draft', 'returned', 'legacy'], true), 409, 'This DTR is locked. Ask for a reviewed correction.');
                $entry->forceFill(['time_source' => 'live', 'hr_status' => 'draft', 'time_in' => $entry->time_in ?: $local->format('H:i'), 'time_out' => null])->save();
                $shift = HrShift::create(['hr_employee_id' => $employee->id, 'work_date' => $local->toDateString(), 'timezone' => $setting->timezone,
                    'started_at' => $now, 'scheduled_end_at' => $finish?->utc(), 'schedule_snapshot' => [...$day, 'max_working_seconds' => self::MAX_WORKING_SECONDS], 'active_guard' => 1]);
            } else {
                abort_unless($shift, 409, 'There is no open shift.');
                $break = $shift->breaks()->whereNull('ended_at')->first();
                if ($action === 'break_out') {
                    abort_if($break, 409, 'You are already on break.');
                    $shift->breaks()->create(['started_at' => $now, 'active_guard' => 1]);
                } elseif ($action === 'break_in') {
                    abort_unless($break, 409, 'There is no open break.');
                    $break->update(['ended_at' => $now, 'active_guard' => null]);
                } else {
                    if ($break) {
                        $break->update(['ended_at' => $now, 'active_guard' => null]);
                    }
                    $shift->update(['ended_at' => $now, 'active_guard' => null]);
                    DtrEntry::where('user_id', $employee->user_id)->whereDate('work_date', $shift->work_date)
                        ->update(['time_out' => $now->setTimezone($shift->timezone)->format('H:i')]);
                }
            }
            $employee->forceFill(['clock_version' => $expectedVersion + 1])->save();
            DB::table('hr_clock_requests')->insert(['hr_employee_id' => $employee->id, 'request_id' => $requestId, 'action' => $action, 'hr_shift_id' => $shift->id, 'created_at' => $now]);
            app(HrAudit::class)->record($employee->user, 'clock.'.$action, $shift, ['employee_id' => $employee->id, 'version' => $expectedVersion + 1]);

            return $shift->fresh('breaks');
        }, 3);

        // Reject a late action only after the automatic closure has committed.
        abort_if($automaticallyClosed && $action !== 'clock_out', 409, 'The 10-hour work limit was reached and your shift was automatically closed. Refresh your clock.');

        return $result;
    }
}
