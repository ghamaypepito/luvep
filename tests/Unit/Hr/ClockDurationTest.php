<?php

namespace Tests\Unit\Hr;

use App\Models\HrBreak;
use App\Models\HrShift;
use App\Services\Hr\ClockService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ClockDurationTest extends TestCase
{
    public function test_overlapping_break_intervals_count_only_once_and_are_clamped_to_shift(): void
    {
        $shift = new HrShift(['started_at' => '2026-10-19 08:00:00', 'ended_at' => '2026-10-19 10:00:00']);
        $shift->setRelation('breaks', collect([
            new HrBreak(['started_at' => '2026-10-19 07:50:00', 'ended_at' => '2026-10-19 08:10:00']),
            new HrBreak(['started_at' => '2026-10-19 08:05:00', 'ended_at' => '2026-10-19 08:20:00']),
        ]));
        $totals = app(ClockService::class)->totals($shift, CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
        $this->assertSame(6000, $totals['working_seconds']);
        $this->assertSame(1200, $totals['break_seconds']);
    }
}
