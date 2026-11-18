<?php

namespace App\Console\Commands;

use App\Jobs\Hr\DeliverHrNotification;
use App\Models\HrNotificationDelivery;
use Illuminate\Console\Command;

class HrDispatchNotifications extends Command
{
    protected $signature = 'hr:dispatch-notifications';

    protected $description = 'Queue pending HR notification deliveries';

    public function handle(): int
    {
        if (! config('hr.enabled') || ! config('hr.notifications_enabled')) {
            return self::SUCCESS;
        }
        HrNotificationDelivery::where('status', 'pending')->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->limit(500)->get()->each(fn ($d) => DeliverHrNotification::dispatch($d->id));

        return self::SUCCESS;
    }
}
