<?php

namespace App\Console\Commands\Hr;

use App\Models\HrChecklistTask;
use App\Models\HrMilestone;
use App\Services\Hr\HrEvents;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NotifyDueWork extends Command
{
    protected $signature = 'hr:notify-due-work';

    protected $description = 'Notify authorized recipients once for each due HR deadline';

    public function handle(): int
    {
        if (! config('hr.enabled')) {
            return self::SUCCESS;
        }
        foreach ([HrMilestone::class, HrChecklistTask::class] as $class) {
            $class::whereNull('completed_at')->select('id')->chunkById(100, function ($rows) use ($class) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($class, $row) {
                        $item = $class::whereKey($row->id)->lockForUpdate()->first();
                        if (! $item || $item->completed_at) {
                            return;
                        }$milestone = $item instanceof HrMilestone;
                        $employee = $milestone ? $item->employee : $item->checklist->employee;
                        if ($employee->status !== 'active' || ! app(\App\Services\Hr\HrAccess::class)->employee($employee->user)) {
                            return;
                        }$today = now($employee->schedule?->timezone ?? 'UTC')->toDateString();
                        $date = $item->due_date->toDateString();
                        if ($milestone ? $date > $today : $date >= $today) {
                            return;
                        }$type = $milestone ? 'milestone.due' : 'checklist.overdue';
                        $revision = $milestone ? $item->deadline_revision : $date;
                        app(HrEvents::class)->record($type, $item, null, "{$type}:{$item->id}:{$revision}");
                    });
                }
            });
        }

        return self::SUCCESS;
    }
}
