<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Services\EmailAutomationService;
use Illuminate\Console\Command;

/**
 * Fires the time-based email-automation events that can't fire off a user
 * action: a lead going cold, an engagement pack expiring unsigned, an invoice
 * going overdue. Runs nightly. Each condition uses a one-day window (crossed
 * exactly N days ago) so a daily run fires each case once, not every night.
 *
 * fire() is a no-op when the event has no enabled messages, so this is safe to
 * run whether or not an admin has configured these events.
 */
class EmailAutomationSweep extends Command
{
    protected $signature = 'email:automation-sweep';

    protected $description = 'Fire time-based email automations (cold leads, expired packs, overdue invoices)';

    public function handle(EmailAutomationService $automation): int
    {
        $fired = 0;

        // Lead went cold — an immigration case, not yet engaged, untouched for 7
        // days (crossed the 7-day mark in the last day).
        Lead::query()->where('is_immigration_case', true)
            ->whereNull('engagement_sent_at')
            ->whereBetween('updated_at', [now()->subDays(8), now()->subDays(7)])
            ->chunkById(200, function ($leads) use ($automation, &$fired) {
                foreach ($leads as $lead) {
                    $automation->fire('immigration.lead.cold', $lead, []);
                    $fired++;
                }
            });

        // Pack expired unsigned — the pack was sent ~14 days ago and the written
        // agreement still isn't signed.
        Lead::query()->where('is_immigration_case', true)
            ->whereBetween('engagement_sent_at', [now()->subDays(15), now()->subDays(14)])
            ->whereDoesntHave('documents', function ($q) {
                $q->where('source_variant', 'engagement:written_agreement')->whereNotNull('client_signed_at');
            })
            ->chunkById(200, function ($leads) use ($automation, &$fired) {
                foreach ($leads as $lead) {
                    $automation->fire('immigration.engagement.expired', $lead, []);
                    $fired++;
                }
            });

        // Payment overdue — an invoice was generated ~14 days ago (7-day terms +
        // 7 days overdue) and no proof of payment has been approved.
        $overdueLeadIds = LeadDocument::where('source_variant', 'invoice')
            ->whereBetween('created_at', [now()->subDays(15), now()->subDays(14)])
            ->pluck('lead_id')->unique();

        foreach ($overdueLeadIds as $leadId) {
            $lead = Lead::find($leadId);
            if (! $lead) {
                continue;
            }
            $unpaid = \App\Services\PaymentProofService::invoiceQuery($lead)
                ->whereBetween('created_at', [now()->subDays(15), now()->subDays(14)])
                ->with('invoicePayments')->get()
                ->contains(fn ($invoice) => ! app(\App\Services\PaymentProofService::class)->invoiceSummary($invoice)['paid']);
            if (! $unpaid) {
                continue;
            }
            if ($lead) {
                $automation->fire('immigration.invoice.overdue', $lead, []);
                $fired++;
            }
        }

        $this->info("Email automation sweep complete — {$fired} event(s) fired.");

        return self::SUCCESS;
    }
}
