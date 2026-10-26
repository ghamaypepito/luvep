<?php

namespace App\Console\Commands;

use App\Models\Agreement;
use App\Models\InstallmentPlan;
use App\Models\PreScreening;
use App\Services\EmailAutomationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Onboarding reminder sweep. After the adviser sends the written agreement the
 * client is expected to SIGN within `onboarding.sign_days`, then CHOOSE
 * pay-now/pay-later within `onboarding.choose_days`. This chases both windows
 * once they elapse: one reminder per window (stamped on the agreement so a
 * re-run never double-sends). Config-driven automation — a no-op for a window
 * whose reminder message isn't enabled. Procedural, not immigration advice.
 */
class OnboardingReminders extends Command
{
    protected $signature = 'immigration:onboarding-reminders';

    protected $description = 'Remind clients to sign the agreement / choose a payment option after the deadline elapses';

    public function handle(EmailAutomationService $automation): int
    {
        $signDays = (int) config('immigration.onboarding.sign_days', 5);
        $chooseDays = (int) config('immigration.onboarding.choose_days', 5);
        $now = Carbon::now();

        $signCount = 0;
        $chooseCount = 0;

        // SIGN reminder — sent/viewed, past the sign-by date, not yet reminded.
        $awaitingSign = Agreement::whereIn('status', [Agreement::STATUS_SENT, Agreement::STATUS_VIEWED])
            ->whereNull('sign_reminded_at')
            ->whereNotNull('sent_at')
            ->get();

        foreach ($awaitingSign as $a) {
            $signBy = $a->sent_at->copy()->addDays($signDays);
            if ($now->lessThan($signBy)) {
                continue;
            }
            $lead = $a->lead;
            if (! $lead) {
                continue;
            }
            $token = optional(PreScreening::where('lead_id', $lead->id)->latest('id')->first())->token;

            try {
                $sent = $automation->fireAnyRecipient('immigration.engagement.reminder', $lead, [
                    'engagement_url' => $token ? rtrim((string) config('app.url'), '/').'/application/'.$token : null,
                    'sign_by' => $signBy->toFormattedDateString(),
                ]);
                if ($sent) {
                    $a->forceFill(['sign_reminded_at' => $now])->save();
                    $signCount++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // CHOOSE reminder — signed, a DRAFT plan still awaiting the client's
        // pay-now/pay-later choice, past the choose-by date, not yet reminded.
        $signed = Agreement::where('status', Agreement::STATUS_SIGNED)
            ->whereNull('choose_reminded_at')
            ->whereNotNull('signed_at')
            ->get();

        foreach ($signed as $a) {
            $chooseBy = $a->signed_at->copy()->addDays($chooseDays);
            if ($now->lessThan($chooseBy)) {
                continue;
            }
            $lead = $a->lead;
            if (! $lead) {
                continue;
            }
            // Only chase when a plan is drafted and still unchosen.
            $hasDraftPlan = InstallmentPlan::where('lead_id', $lead->id)
                ->where('status', InstallmentPlan::STATUS_DRAFT)->exists();
            if (! $hasDraftPlan) {
                continue;
            }

            try {
                $sent = $automation->fireAnyRecipient('immigration.payment.choice.reminder', $lead, [
                    'choose_by' => $chooseBy->toFormattedDateString(),
                ]);
                if ($sent) {
                    $a->forceFill(['choose_reminded_at' => $now])->save();
                    $chooseCount++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("Onboarding reminders: {$signCount} sign, {$chooseCount} choose-payment.");

        return self::SUCCESS;
    }
}
