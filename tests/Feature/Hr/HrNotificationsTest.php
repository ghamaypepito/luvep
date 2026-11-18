<?php

namespace Tests\Feature\Hr;

use App\Jobs\Hr\DeliverHrNotification;
use App\Models\DtrLeave;
use App\Models\HrEmployee;
use App\Models\HrNotificationDelivery;
use App\Models\User;
use App\Notifications\HrActionRequired;
use App\Services\Hr\HrEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HrNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['hr.enabled' => true, 'hr.notifications_enabled' => true]);
        Queue::fake();
        $employee = User::factory()->create(['role' => 'sales']);
        $manager = User::factory()->create(['role' => 'admin']);
        $admin = User::factory()->create(['role' => 'super_admin']);
        HrEmployee::create(['user_id' => $employee->id, 'status' => 'active', 'manager_user_id' => $manager->id]);
        $leave = DtrLeave::create(['user_id' => $employee->id, 'type' => 'Personal / Sick Leave', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'reason' => 'PRIVATE MEDICAL DETAIL']);

        return [$employee, $manager, $admin, $leave];
    }

    public function test_unique_manager_admin_deliveries_exclude_actor_and_inactive_recipients(): void
    {
        [$employee,$manager,$admin,$leave] = $this->fixture();
        User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $event = app(HrEvents::class)->record('leave.submitted', $leave, $employee, 'leave-test');
        app(HrEvents::class)->record('leave.submitted', $leave, $employee, 'leave-test');
        $this->assertDatabaseCount('hr_notification_deliveries', 4);
        $this->assertEqualsCanonicalizing([$manager->id, $admin->id], HrNotificationDelivery::pluck('user_id')->unique()->all());
        $this->assertEqualsCanonicalizing(['database', 'mail'], HrNotificationDelivery::pluck('channel')->unique()->all());
    }

    public function test_mail_and_inbox_are_minimal_and_delivery_retry_is_idempotent(): void
    {
        [$employee,$manager,,$leave] = $this->fixture();
        Notification::fake();
        app(HrEvents::class)->record('leave.submitted', $leave, $employee, 'safe-event');
        foreach (HrNotificationDelivery::all() as $delivery) {
            (new DeliverHrNotification($delivery->id))->handle();
            (new DeliverHrNotification($delivery->id))->handle();
        }
        Notification::assertSentTo($manager, HrActionRequired::class, function ($notification, $channels) use ($manager) {
            $data = $notification->toArray($manager);
            $this->assertStringNotContainsString('PRIVATE MEDICAL DETAIL', json_encode($data));
            $this->assertStringNotContainsString('Sick', json_encode($data));

            return $channels === ['mail'];
        });
        $this->assertSame(2, \Illuminate\Notifications\DatabaseNotification::count());
        $this->assertSame(4, HrNotificationDelivery::where('status', 'sent')->count());
    }

    public function test_revoked_recipient_and_invalid_email_are_skipped(): void
    {
        [$employee,$manager,$admin,$leave] = $this->fixture();
        Notification::fake();
        app(HrEvents::class)->record('leave.submitted', $leave, $employee, 'revoke-event');
        $manager->update(['role' => 'client']);
        $admin->update(['email' => 'not an email']);
        foreach (HrNotificationDelivery::all() as $delivery) {
            (new DeliverHrNotification($delivery->id))->handle();
        }
        Notification::assertNothingSent();
        $this->assertSame(3, HrNotificationDelivery::where('status', 'skipped')->count());
        $this->assertSame(1, \Illuminate\Notifications\DatabaseNotification::count());
    }

    public function test_mail_failure_preserves_business_record_and_records_retry(): void
    {
        [$employee,$manager,,$leave] = $this->fixture();
        app(HrEvents::class)->record('leave.submitted', $leave, $employee, 'failed-mail');
        $delivery = HrNotificationDelivery::where('user_id', $manager->id)->where('channel', 'mail')->first();
        Notification::shouldReceive('sendNow')->once()->andThrow(new \RuntimeException('sensitive transport details'));
        (new DeliverHrNotification($delivery->id))->handle();
        $this->assertModelExists($leave);
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertNotNull($delivery->fresh()->next_attempt_at);
        $this->assertStringNotContainsString('sensitive', $delivery->fresh()->last_error);
    }

    public function test_disabled_notifications_do_not_backfill_old_events(): void
    {
        [$employee,,,$leave] = $this->fixture();
        config(['hr.notifications_enabled' => false]);
        app(HrEvents::class)->record('leave.submitted', $leave, $employee, 'disabled');
        config(['hr.notifications_enabled' => true]);
        app(HrEvents::class)->record('leave.submitted',$leave,$employee,'disabled');
        $this->assertDatabaseCount('hr_notification_deliveries',0);
    }
}
