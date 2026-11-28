<?php

namespace App\Console\Commands;

use App\Models\AccommodationMoveOut;
use App\Models\Setting;
use App\Services\Accommodation\MoveOutWorkflow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendAccommodationMoveOutEmails extends Command
{
    protected $signature = 'accommodation:send-move-out-emails';

    protected $description = 'Send enabled Exalt renewal check-ins and move-out reminders due today in New Zealand';

    public function handle(MoveOutWorkflow $workflow): int
    {
        if (! Setting::get(AccommodationMoveOut::AUTOMATION_SETTING, false)) {
            return self::SUCCESS;
        }

        $today = now('Pacific/Auckland')->toDateString();
        $failed = 0;
        AccommodationMoveOut::where('automatic_email', true)->whereNull('closed_at')
            ->where(fn ($query) => $query
                ->where(fn ($checkIns) => $checkIns->where('stage', 'check_in')->whereDate('check_in_on', $today))
                ->orWhere(fn ($reminders) => $reminders->where('stage', 'decision')->where('decision', 'move_out')->whereDate('reminder_on', $today)))
            ->eachById(function (AccommodationMoveOut $candidate) use ($workflow, $today, &$failed) {
                try {
                    DB::transaction(function () use ($candidate, $workflow, $today) {
                        // Check the stored switch again before each email. The scheduler could be running during a pause.
                        $setting = Setting::where('key', AccommodationMoveOut::AUTOMATION_SETTING)->lockForUpdate()->first();
                        if (! filter_var($setting?->value, FILTER_VALIDATE_BOOLEAN)) {
                            return;
                        }
                        $record = AccommodationMoveOut::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                        if (! $record->automatic_email || $record->closed_at) {
                            return;
                        }

                        $action = match (true) {
                            $record->stage === 'check_in' && $record->check_in_on->toDateString() === $today
                                && ! $record->events()->where('action', 'check_in')->exists() => 'check_in',
                            $record->stage === 'decision' && $record->decision === 'move_out'
                                && $record->reminder_on?->toDateString() === $today => 'remind',
                            default => null,
                        };
                        if ($action) {
                            $stored = [];
                            $workflow->apply($record, $action, [], 'Scheduled email', $stored);
                        }
                    });
                } catch (Throwable $error) {
                    report($error);
                    $this->error('Could not send the scheduled email for move-out record #'.$candidate->id.'.');
                    $failed++;
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
