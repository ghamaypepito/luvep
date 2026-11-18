<?php

namespace App\Services\Hr;

use App\Models\HrEmployee;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class WorkingCalendar
{
    public function day(HrEmployee $employee, string $date): array
    {
        $setting = $employee->schedule;
        if (! $setting?->is_complete || ! $setting->timezone) {
            throw ValidationException::withMessages(['schedule' => 'HR must complete your working schedule first.']);
        }
        $day = CarbonImmutable::parse($date, $setting->timezone);
        $schedule = $setting->scheduleForDate($date);
        if (empty($setting->weekly_schedule) && $day->isSunday()) {
            $schedule['working'] = false;
        }
        $team = strtolower($setting->team ?? '');
        $country = $setting->timezone === 'Asia/Manila' || str_contains($team, 'philippin') ? 'PH' : ($setting->timezone === 'Pacific/Auckland' || str_contains($team, 'zealand') ? 'NZ' : null);
        $holiday = $country ? config('dtr_holidays.'.$country.'.'.$date) : null;

        return ['date' => $date, 'working' => $schedule['working'] && ! $holiday, 'holiday' => $holiday, 'schedule' => $schedule, 'timezone' => $setting->timezone];
    }

    public function duration(HrEmployee $employee, string $start, string $end, ?string $halfDay): array
    {
        $from = CarbonImmutable::parse($start);
        $to = CarbonImmutable::parse($end);
        if ($to->lessThan($from) || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['dates' => 'Choose a valid period of no more than one year.']);
        }
        if (in_array($halfDay, ['AM', 'PM'], true) && $start !== $end) {
            throw ValidationException::withMessages(['half_day' => 'A half-day request must be for one date.']);
        }
        $days = [];
        $total = 0.0;
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            $day = $this->day($employee, $d->toDateString());
            $day['fraction'] = $day['working'] ? (in_array($halfDay, ['AM', 'PM'], true) ? 0.5 : 1.0) : 0.0;
            $total += $day['fraction'];
            $days[] = $day;
        }
        if ($total === 0.0) {
            throw ValidationException::withMessages(['dates' => 'There are no scheduled working days in this period.']);
        }

        return ['total_days' => $total, 'days' => $days, 'half_day' => $halfDay];
    }

    public function responseDue(HrEmployee $employee): string
    {
        $d = CarbonImmutable::instance(now($employee->schedule?->timezone ?? 'UTC'));
        $count = 0;
        for ($i = 0; $i < 370; $i++) {
            $d = $d->addDay();
            if ($this->day($employee, $d->toDateString())['working']) {
                $count++;
            }if ($count === 3) {
                return $d->toDateString();
            }
        }
        throw ValidationException::withMessages(['schedule' => 'No working days are configured for a response deadline.']);
    }
}
