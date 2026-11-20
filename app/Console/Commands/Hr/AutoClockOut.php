<?php

namespace App\Console\Commands\Hr;

use App\Models\HrShift;
use App\Services\Hr\ClockService;
use Illuminate\Console\Command;

class AutoClockOut extends Command
{
    protected $signature = 'hr:auto-clock-out {--dry-run : Count due shifts without closing them}';

    protected $description = 'Close new live shifts at ten working hours, excluding breaks';

    public function handle(ClockService $clock): int
    {
        if (! config('hr.enabled')) {
            return self::SUCCESS;
        }
        $count = 0;
        HrShift::whereNull('ended_at')->where('started_at', '<=', now('UTC')->subSeconds(ClockService::MAX_WORKING_SECONDS))
            ->select(['id', 'hr_employee_id'])->chunkById(100, function ($shifts) use ($clock, &$count) {
                foreach ($shifts as $shift) {
                    $count += (int) $clock->autoClockOut($shift->hr_employee_id, (bool) $this->option('dry-run'));
                }
            });
        $this->info($this->option('dry-run') ? "{$count} shift(s) would automatically close." : "{$count} shift(s) automatically closed.");

        return self::SUCCESS;
    }
}
