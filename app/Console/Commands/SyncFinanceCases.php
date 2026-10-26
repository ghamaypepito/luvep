<?php

namespace App\Console\Commands;

use App\Models\CaseFinancePayment;
use App\Models\CaseFinancial;
use App\Models\InstallmentPlan;
use App\Models\Lead;
use App\Services\Finance\FinanceSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the Finance portal's Accounts Receivable from existing immigration
 * case money — invoices (CaseFinancial), installment plans, and every recorded
 * payment. Safe to re-run: FinanceSyncService is idempotent (one AR per case,
 * each payment mirrored once by its source id).
 */
class SyncFinanceCases extends Command
{
    protected $signature = 'finance:sync-cases';

    protected $description = 'Backfill Finance Accounts Receivable from immigration case invoices + payments.';

    public function handle(FinanceSyncService $sync): int
    {
        $leadIds = collect()
            ->merge(CaseFinancial::pluck('lead_id'))
            ->when(Schema::hasTable('installment_plans'), fn ($c) => $c->merge(InstallmentPlan::pluck('lead_id')))
            ->merge(CaseFinancePayment::pluck('lead_id'))
            ->filter()->unique()->values();

        $this->info("Syncing {$leadIds->count()} case(s) into Finance AR…");

        $invoices = 0;
        $payments = 0;

        foreach (Lead::whereIn('id', $leadIds)->get() as $lead) {
            if ($sync->syncForLead($lead)) {
                $invoices++;
            }
            foreach (CaseFinancePayment::where('lead_id', $lead->id)->get() as $payment) {
                $sync->mirrorPayment($payment);
                $payments++;
            }
        }

        $this->info("Done — {$invoices} AR invoice(s) up to date, {$payments} case payment(s) mirrored.");

        return self::SUCCESS;
    }
}
