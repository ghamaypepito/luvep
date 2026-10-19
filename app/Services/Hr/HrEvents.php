<?php

namespace App\Services\Hr;

use App\Jobs\Hr\DeliverHrNotification;
use App\Models\HrEvent;
use App\Models\HrNotificationDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HrEvents
{
    public function record(string $type, Model $subject, User $actor, string $deduplicationKey): HrEvent
    {
        return DB::transaction(function () use ($type, $subject, $actor, $deduplicationKey) {
            $event = HrEvent::firstOrCreate(['deduplication_key' => $deduplicationKey], ['type' => $type, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(), 'actor_id' => $actor->id, 'created_at' => now()]);
            if ($event->wasRecentlyCreated && config('hr.notifications_enabled')) {
                foreach (app(HrNotificationRecipients::class)->forEvent($event) as $user) {
                    foreach (['database', 'mail'] as $channel) {
                        $delivery = HrNotificationDelivery::firstOrCreate(['event_id' => $event->id, 'user_id' => $user->id, 'channel' => $channel], ['notification_id' => (string) Str::uuid()]);
                        DB::afterCommit(function () use ($delivery) {
                            try {
                                DeliverHrNotification::dispatch($delivery->id);
                            } catch (\Throwable) {
                                Log::warning('HR notification dispatch pending retry', ['delivery_id' => $delivery->id]);
                            }
                        });
                    }
                }
            }

            return $event;
        });
    }
}
