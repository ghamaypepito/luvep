<?php

namespace App\Services\Hr;

use App\Models\DtrEntry;
use App\Models\HrEmployee;
use App\Models\HrShift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClockService
{
    public function totals(HrShift $shift, CarbonImmutable $now): array
    {
        $start = $shift->started_at->getTimestamp();
        $end = ($shift->ended_at ?? $now)->getTimestamp();
        $intervals = $shift->breaks->map(fn ($b) => [max($start, $b->started_at->getTimestamp()), min($end, ($b->ended_at ?? $now)->getTimestamp())])
            ->filter(fn ($b) => $b[1] > $b[0])->sortBy(fn ($b) => $b[0]);
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
            ...($shift ? $this->totals($shift, $now) : ['working_seconds' => 0, 'break_seconds' => 0])];
    }

    public function transition(HrEmployee $employee, string $action, string $requestId, int $expectedVersion): HrShift
    {
        abort_unless(in_array($action, ['clock_in', 'break_out', 'break_in', 'clock_out'], true), 422);

        return DB::transaction(function () use ($employee, $action, $requestId, $expectedVersion) {
            $employee = HrEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            abort_unless($employee->user && app(HrAccess::class)->employee($employee->user)?->id === $employee->id, 403);
            $prior = DB::table('hr_clock_requests')->where('hr_employee_id', $employee->id)->where('request_id', $requestId)->first();
            if ($prior) {
                abort_unless($prior->action === $action, 409, 'This action key was already used. Refresh your clock.');

                return HrShift::findOrFail($prior->hr_shift_id);
            }
            abort_unless((int) $employee->clock_version === $expectedVersion, 409, 'Your clock changed elsewhere. Refresh and try again.');
            $now = CarbonImmutable::instance(now('UTC'));
            $shift = HrShift::where('hr_employee_id', $employee->id)->whereNull('ended_at')->first();
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
                    'started_at' => $now, 'scheduled_end_at' => $finish?->utc(), 'schedule_snapshot' => $day, 'active_guard' => 1]);
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
    }
}
