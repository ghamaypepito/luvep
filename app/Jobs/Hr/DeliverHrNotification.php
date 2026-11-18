<?php

namespace App\Jobs\Hr;

use App\Models\HrNotificationDelivery;
use App\Notifications\HrActionRequired;
use App\Services\Hr\HrNotificationRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class DeliverHrNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        if (! config('hr.enabled') || ! config('hr.notifications_enabled')) {
            return;
        }
        DB::transaction(function () {
            $delivery = HrNotificationDelivery::whereKey($this->deliveryId)->lockForUpdate()->first();
            if (! $delivery || $delivery->status !== 'pending' || $delivery->next_attempt_at?->isFuture()) {
                return;
            }
            $user = $delivery->user;
            $event = $delivery->event;
            if (! $user || ! $event || ! app(HrNotificationRecipients::class)->forEvent($event)->contains('id', $user->id) || ($delivery->channel === 'mail' && ! filter_var($user->email, FILTER_VALIDATE_EMAIL))) {
                $delivery->update(['status' => 'skipped', 'last_error' => 'Recipient no longer eligible']);

                return;
            }
            $delivery->attempts++;
            try {
                $notification = new HrActionRequired($event);
                $notification->id = $delivery->notification_id;
                if ($delivery->channel === 'database') {
                    DB::table('notifications')->insertOrIgnore(['id' => $delivery->notification_id, 'type' => HrActionRequired::class, 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id, 'data' => json_encode($notification->toArray($user), JSON_THROW_ON_ERROR), 'read_at' => null, 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    Notification::sendNow($user, $notification, ['mail']);
                }
                $delivery->forceFill(['status' => 'sent', 'sent_at' => now(), 'next_attempt_at' => null, 'last_error' => null])->save();
            } catch (\Throwable $error) {
                $delivery->forceFill(['status' => $delivery->attempts >= 5 ? 'failed' : 'pending', 'last_error' => class_basename($error), 'next_attempt_at' => now()->addSeconds(min(3600, 60 * (2 ** ($delivery->attempts - 1))))])->save();
            }
        });
    }
}
