<?php

use App\Services\NewsFeedService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Warm the NZ migration news cache hourly so visitors never trigger the
// HTTP fetch on the critical render path. Cache key + TTL are managed by
// the service itself.
Schedule::call(fn () => (new NewsFeedService)->refresh())
    ->hourly()
    ->name('news-feed-refresh')
    ->withoutOverlapping();

// Fire any bulk-email campaign whose scheduled time has arrived.
Schedule::command('campaigns:dispatch-due')
    ->everyMinute()
    ->name('dispatch-due-campaigns')
    ->withoutOverlapping();

// Fire any scheduled event email whose time has arrived.
Schedule::command('events:dispatch-due-emails')
    ->everyMinute()
    ->name('dispatch-due-event-emails')
    ->withoutOverlapping();

// Fire any due booking reminder (booking_confirmation_2..5).
Schedule::command('bookings:dispatch-due-reminders')
    ->everyMinute()
    ->name('dispatch-due-booking-reminders')
    ->withoutOverlapping();

// Pull inbound email replies from the monitored mailbox into the system.
Schedule::command('email:sync-replies')
    ->everyFiveMinutes()
    ->name('sync-email-replies')
    ->withoutOverlapping();

// Warn advisers + admins before an IAA licence lapses (Build 12 §2). Daily,
// mid-morning NZ time so the email lands during the working day; exact-day
// threshold matching means once-daily sends each warning once.
Schedule::command('immigration:licence-expiry-check')
    ->dailyAt('09:00')
    ->timezone('Pacific/Auckland')
    ->name('immigration-licence-expiry-check')
    ->withoutOverlapping();

// Nightly case-findings sweep (Build 12 phase 3) so time-based rules (idle
// cases, unanswered document requests, passport expiry) refresh even on
// untouched cases. Runs before the working day; per-case work is queued.
Schedule::command('immigration:evaluate-findings')
    ->dailyAt('04:30')
    ->timezone('Pacific/Auckland')
    ->name('immigration-evaluate-findings')
    ->withoutOverlapping();

// Time-based email automations (cold leads, expired packs, overdue invoices).
// Mid-morning NZ so any client emails land during the working day; the one-day
// windows in the command dedupe so each case fires once.
Schedule::command('email:automation-sweep')
    ->dailyAt('09:30')
    ->timezone('Pacific/Auckland')
    ->name('email-automation-sweep')
    ->withoutOverlapping();

// Pull Google Business Profile reviews into the moderation queue (source=google,
// unpublished until a teammate approves). Reviews change slowly, so once daily is
// plenty; the upsert is idempotent by external_id. No-ops safely when the
// GOOGLE_BUSINESS_* env vars aren't set.
Schedule::command('google:sync-reviews')
    ->dailyAt('05:00')
    ->timezone('Pacific/Auckland')
    ->name('google-sync-reviews')
    ->withoutOverlapping();

// Daily "you have overdue tasks" digest — emails each staff member the tasks
// assigned to them that are past due and still open (ClickUp-style). Early NZ
// morning so it's waiting at the start of the working day; the mails are queued.
Schedule::command('tasks:overdue-digest')
    ->dailyAt('07:30')
    ->timezone('Pacific/Auckland')
    ->name('tasks-overdue-digest')
    ->withoutOverlapping();

Schedule::command('hr:dispatch-notifications')->everyMinute()->withoutOverlapping();

Schedule::command('hr:notify-due-work')->hourly()->withoutOverlapping();

Schedule::command('hr:expire-exports')->hourly()->withoutOverlapping();

Schedule::command('tickets:archive')->dailyAt('02:00')->timezone('Asia/Manila')->withoutOverlapping();

Schedule::command('education:dispatch-registration-outreach')->everyMinute()->withoutOverlapping();

\Illuminate\Support\Facades\Schedule::command('education:dispatch-rsvps')->everyMinute()->withoutOverlapping();
