<?php

namespace App\Providers;

use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // SMS transport: Brevo when configured, else Twilio, else a no-op
        // provider so the system runs without SMS creds in dev.
        $this->app->bind(\App\Contracts\SmsProvider::class, function () {
            if ($key = config('services.brevo.sms_key')) {
                return new \App\Services\Sms\BrevoSmsProvider($key, config('services.brevo.sms_sender'));
            }

            if ($sid = config('services.twilio.sid')) {
                return new \App\Services\Sms\TwilioSmsProvider($sid, config('services.twilio.token'), config('services.twilio.from'));
            }

            return new \App\Services\Sms\NullSmsProvider;
        });

        // Build 11.D Phase 3 — E-signature provider. StubSignatureProvider
        // is the in-CRM stub (typed name + canvas + IP + UA + timestamp).
        // Build 11.E will rebind this to a real provider (HelloSign /
        // DocuSign) without touching controllers or views.
        $this->app->bind(
            \App\Contracts\SignatureProvider::class,
            \App\Services\Immigration\StubSignatureProvider::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Flip a MessageLog from 'queued' to 'sent' once the email actually
        // reaches the mail server (fires on the queue worker).
        Event::listen(MessageSent::class, \App\Listeners\MarkMessageLogSent::class);

        // Build 12 §2 — advice-bearing content is licence-gated, not
        // role-gated. `approve-advice-bearing` is the class-level ability used
        // for UI affordances; as the advice-bearing models land they are also
        // mapped to AdviceBearingPolicy so `authorize('approve', $model)`
        // resolves here. The gate is the current IAA licence, so a lapsed
        // licence — or an AI/system actor with none — can never approve.
        Gate::define('approve-advice-bearing', [\App\Policies\AdviceBearingPolicy::class, 'approve']);

        // Already-logged-in users hitting a 'guest' route (e.g. /login) land on
        // their own home — admins on /admin/dashboard, portal staff on their portal.
        RedirectIfAuthenticated::redirectUsing(
            fn ($request) => $request->user()?->homeRoute() ?? '/'
        );

        // Single source of truth for password strength (D4): min 8, letters,
        // mixed case, numbers. Used via Password::defaults() everywhere a
        // password is set — reset, admin user CRUD, lead-portal setup.
        Password::defaults(fn () => Password::min(8)->letters()->mixedCase()->numbers());

        // Public /track/{code} throttle — 30 requests/min per code+IP to
        // blunt tracking-code enumeration without bothering real leads.
        RateLimiter::for('tracker', fn (Request $request) => Limit::perMinute(30)
            ->by(($request->route('code') ?? 'none').'|'.$request->ip()));

        // Build the reset URL on the app's own domain (config, not env) so it
        // points at our Inertia reset page rather than a framework default.
        ResetPassword::createUrlUsing(fn ($notifiable, string $token) => rtrim(config('app.url'), '/')
            .'/reset-password/'.$token.'?email='.urlencode($notifiable->getEmailForPasswordReset()));

        // Link immigration case money into the Finance portal's Accounts
        // Receivable: a case's financials / installment plan drive one AR
        // invoice, and every case payment (manual receipt or installment, which
        // both write a CaseFinancePayment) is mirrored into its ledger. The
        // service is best-effort + idempotent, so it never breaks case writes.
        $financeSync = fn () => $this->app->make(\App\Services\Finance\FinanceSyncService::class);
        \App\Models\CaseFinancial::saved(function ($cf) use ($financeSync) {
            if ($lead = \App\Models\Lead::find($cf->lead_id)) {
                $financeSync()->syncForLead($lead);
            }
        });
        \App\Models\CaseFinancePayment::created(fn ($p) => $financeSync()->mirrorPayment($p));
        \App\Models\CaseFinancePayment::deleted(function ($p) use ($financeSync) {
            $financeSync()->removeMirroredPayment($p);
            $this->app->make(\App\Services\Immigration\InstallmentPlanService::class)->forgetPayment($p);
        });
        if (class_exists(\App\Models\InstallmentPlan::class)) {
            \App\Models\InstallmentPlan::saved(function ($plan) use ($financeSync) {
                if ($lead = \App\Models\Lead::find($plan->lead_id)) {
                    $financeSync()->syncForLead($lead);
                }
            });
        }

        // Professional tone, matching the lead-portal invitation email (D7).
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = rtrim(config('app.url'), '/').'/reset-password/'.$token
                .'?email='.urlencode($notifiable->getEmailForPasswordReset());

            return (new MailMessage)
                ->subject('EP Account Recovery')
                ->greeting('Hi '.($notifiable->name ?? 'there').',')
                ->line('We received a request to reset the password for your EP account.')
                ->action('Reset your password', $url)
                ->line('This link expires in 60 minutes.')
                ->line('If you didn’t request a password reset, no action is needed — your password will stay the same.');
        });
    }
}
