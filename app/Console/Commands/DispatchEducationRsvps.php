<?php

namespace App\Console\Commands;

use App\Jobs\SendEducationOutreach;
use App\Models\EducationOutreach;
use App\Services\EducationOutreachService;
use Illuminate\Console\Command;

class DispatchEducationRsvps extends Command
{
    protected $signature = 'education:dispatch-rsvps';

    protected $description = 'Plan confirmed RSVP schedule notices/reminders and retry pending Education outreach';

    public function handle(EducationOutreachService $service): int
    {
        $service->plan();
        foreach (['mail' => 100, 'staff' => 100, 'slack' => 20] as $channel => $limit) {
            if ($channel === 'slack' && ! app(\App\Services\ProgramSlackNotifier::class)->configured()) {
                continue;
            }
            EducationOutreach::where('channel', $channel)->whereNull('sent_at')->whereNull('cancelled_at')
                ->where('attempts', '<', 8)
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orderBy('id')->limit($limit)->get()->each(fn ($d) => SendEducationOutreach::dispatch($d->id));
        }

        return self::SUCCESS;
    }
}
