<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;

/** Civil NZ dates with exclusive next-midnight boundaries, including DST. */
class ReportPeriod
{
    public const TIMEZONE = 'Pacific/Auckland';

    public function __construct(
        public string $preset,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public CarbonImmutable $now,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $now = CarbonImmutable::now(self::TIMEZONE);
        $preset = $request->input('preset', 'this_month');
        $start = $now->startOfMonth();
        $end = $now->startOfDay()->addDay();
        if ($preset === 'custom') {
            $data = $request->validate([
                'from' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$now->toDateString()],
                'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$now->toDateString()],
            ]);
            $start = CarbonImmutable::parse($data['from'], self::TIMEZONE)->startOfDay();
            $end = CarbonImmutable::parse($data['to'], self::TIMEZONE)->startOfDay()->addDay();
        } elseif ($preset === 'today') {
            $start = $now->startOfDay();
        } elseif ($preset === 'this_week') {
            $start = $now->startOfWeek(1)->startOfDay();
        } elseif ($preset === 'this_year') {
            $start = $now->startOfYear();
        } elseif ($preset === 'all') {
            $start = $now->startOfDay(); // Replaced by earliest known event, if any.
        } elseif (in_array($preset, ['quarter', 'two_weeks', 'last_month'], true)) {
            $start = match ($preset) {
                'quarter' => $now->subMonthsNoOverflow(3)->startOfDay(),
                'last_month' => $now->subMonthNoOverflow()->startOfMonth(),
                default => $now->subDays(13)->startOfDay(),
            };
            if ($preset === 'last_month') {
                $end = $now->startOfMonth();
            }
            $preset = 'custom';
        } else {
            $preset = 'this_month';
        }

        return new self($preset, $start, $end, $now);
    }

    public function isAll(): bool
    {
        return $this->preset === 'all';
    }

    public function days(): int
    {
        return (int) $this->start->diff($this->end)->days;
    }

    public function bucketDays(): int
    {
        return $this->days() <= 31 ? 1 : ($this->days() <= 186 ? 7 : max(30, (int) ceil($this->days() / 60)));
    }

    public function contains(CarbonImmutable $at, bool $previous = false): bool
    {
        $start = $previous ? $this->start->subDays($this->days()) : $this->start;
        $end = $previous ? $this->start : $this->end;

        return $at->gte($start) && $at->lt($end) && $at->lte($this->now);
    }

    public function withAllStart(?CarbonImmutable $earliest): self
    {
        return new self($this->preset, $this->isAll() && $earliest ? $earliest->startOfDay() : $this->start, $this->end, $this->now);
    }

    public static function eventDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone(self::TIMEZONE);
        }
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?)?$/D', $value, $m)) {
            return null;
        }
        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (isset($m[4]) && ((int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59))) {
            return null;
        }
        try {
            // Bare dates denote an explicit NZ calendar date; unzoned stored timestamps use the application's UTC storage convention.
            return CarbonImmutable::parse($value, strlen($value) === 10 ? self::TIMEZONE : 'UTC')->setTimezone(self::TIMEZONE);
        } catch (\Throwable) {
            return null;
        }
    }

    public function toArray(): array
    {
        return [
            'preset' => $this->preset, 'from' => $this->start->toDateString(), 'to' => $this->end->subDay()->toDateString(),
            'label' => match ($this->preset) {
                'today' => 'Today', 'this_week' => 'This week', 'this_year' => 'This year', 'all' => 'All',
                'custom' => 'Custom', default => 'This month',
            },
            'days' => $this->days(), 'timezone' => self::TIMEZONE, 'all' => $this->isAll(), 'compare' => ! $this->isAll(),
            'bucket_days' => $this->bucketDays(),
            'bucket_label' => match ($this->bucketDays()) {
                1 => 'Daily', 7 => 'Weekly', default => $this->bucketDays().'-day'
            },
            'previous' => ['from' => $this->start->subDays($this->days())->toDateString(), 'to' => $this->start->subDay()->toDateString()],
        ];
    }
}
