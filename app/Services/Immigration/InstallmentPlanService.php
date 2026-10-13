<?php

namespace App\Services\Immigration;

use App\Models\CaseFinancePayment;
use App\Models\Installment;
use App\Models\InstallmentPlan;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Builds + maintains a case's "pay later" installment plan: a staff-set deposit
 * (covering the INZ fee) then weekly payments across a chosen plan length.
 *
 * Terms (deposit %, plan length) are set by staff at engagement/invoice time as
 * a DRAFT; the client then chooses pay now / pay later on /application. Choosing
 * pay-later activates the draft (generates the weekly schedule). Staff record
 * each payment, which writes a CaseFinancePayment into the existing ledger.
 * Procedural payment tracking — not immigration advice.
 */
class InstallmentPlanService
{
    /** Plan length presets (months => weekly-payment count) from config. */
    public function presets(): array
    {
        return (array) config('immigration.installments.plan_presets', [3 => 13, 6 => 26]);
    }

    public function depositBounds(): array
    {
        return [
            'default' => (float) config('immigration.installments.deposit_percent_default', 25),
            'min' => (float) config('immigration.installments.deposit_percent_min', 20),
            'max' => (float) config('immigration.installments.deposit_percent_max', 30),
        ];
    }

    /**
     * Create / replace the DRAFT plan carrying the staff-set terms + a preview
     * of the figures. No schedule rows yet — those are generated on activate().
     *
     * @param  array{total_amount:float,inz_fee?:float,plan_months:int,deposit_percent:float}  $terms
     */
    public function draft(Lead $lead, array $terms, ?User $by = null): InstallmentPlan
    {
        // Retire any existing non-terminal plan — one live plan per case.
        InstallmentPlan::where('lead_id', $lead->id)
            ->whereIn('status', [InstallmentPlan::STATUS_DRAFT, InstallmentPlan::STATUS_ACTIVE])
            ->update(['status' => InstallmentPlan::STATUS_CANCELLED]);

        $figures = $this->figures($terms);

        return InstallmentPlan::create([
            'lead_id' => $lead->id,
            'payment_type' => InstallmentPlan::PAYMENT_LATER,
            'plan_months' => $figures['plan_months'],
            'weeks' => $figures['weeks'],
            'total_amount' => $figures['total_amount'],
            'deposit_percent' => $figures['deposit_percent'],
            'deposit_amount' => $figures['deposit_amount'],
            'inz_fee' => $figures['inz_fee'],
            'professional_fee' => $figures['professional_fee'],
            'discount_type' => $figures['discount_type'],
            'discount_value' => $figures['discount_value'],
            'discount_amount' => $figures['discount_amount'],
            'admin_fee' => $figures['admin_fee'],
            'financed_amount' => $figures['financed_amount'],
            'weekly_amount' => $figures['weekly_amount'],
            'status' => InstallmentPlan::STATUS_DRAFT,
            'created_by' => $by?->id,
        ]);
    }

    /** Compute the agreed figures from the staff terms (no persistence). */
    public function figures(array $terms): array
    {
        $presets = $this->presets();
        $months = (int) ($terms['plan_months'] ?? array_key_first($presets));
        $weeks = (int) ($presets[$months] ?? max(1, $months * 4));

        $inzFee = round((float) ($terms['inz_fee'] ?? 0), 2);

        // New structured path: professional fee − discount + admin fee + INZ.
        // Legacy callers still pass `total_amount` directly (no discount/admin).
        if (array_key_exists('professional_fee', $terms)) {
            $professional = round((float) ($terms['professional_fee'] ?? 0), 2);

            $discountType = in_array($terms['discount_type'] ?? 'none', ['percent', 'fixed'], true)
                ? $terms['discount_type'] : 'none';
            $discountValue = round((float) ($terms['discount_value'] ?? 0), 2);
            $discountAmount = match ($discountType) {
                'percent' => round($professional * $discountValue / 100, 2),
                'fixed' => round($discountValue, 2),
                default => 0.0,
            };
            $discountAmount = max(0.0, min($discountAmount, $professional));

            $adminFee = round((float) ($terms['admin_fee'] ?? config('immigration.fees.admin_fee', 10)), 2);
            if (config('immigration.fees.admin_fee_gst', false)) {
                $adminFee = round($adminFee * (1 + \App\Models\VisaType::GST_RATE), 2);
            }

            $total = round(($professional - $discountAmount) + $inzFee + $adminFee, 2);
        } else {
            $total = round((float) ($terms['total_amount'] ?? 0), 2);
            $professional = round(max(0, $total - $inzFee), 2);
            $discountType = 'none';
            $discountValue = 0.0;
            $discountAmount = 0.0;
            $adminFee = 0.0;
        }

        $bounds = $this->depositBounds();
        $pct = (float) ($terms['deposit_percent'] ?? $bounds['default']);
        $pct = max($bounds['min'], min($bounds['max'], $pct));

        // Deposit is a % of the total, but must always cover the INZ fee (the
        // deposit "includes" the INZ fee — it is collected upfront).
        $deposit = round($total * $pct / 100, 2);
        if ($deposit < $inzFee) {
            $deposit = $inzFee;
        }
        $deposit = min($deposit, $total);

        $financed = round($total - $deposit, 2);
        $weekly = $weeks > 0 ? round($financed / $weeks, 2) : 0.0;

        return [
            'plan_months' => $months,
            'weeks' => $weeks,
            'total_amount' => $total,
            'deposit_percent' => $pct,
            'deposit_amount' => $deposit,
            'inz_fee' => $inzFee,
            'professional_fee' => $professional,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_amount' => $discountAmount,
            'admin_fee' => $adminFee,
            'financed_amount' => $financed,
            'weekly_amount' => $weekly,
        ];
    }

    /**
     * Activate a draft plan: generate the deposit + weekly schedule rows from
     * the agreed figures, starting on $start (default today).
     */
    public function activate(InstallmentPlan $plan, ?Carbon $start = null): InstallmentPlan
    {
        $start ??= Carbon::today();
        $plan->installments()->delete();

        // Deposit first (sequence 0, due on the start date).
        $plan->installments()->create([
            'sequence' => 0,
            'kind' => Installment::KIND_DEPOSIT,
            'due_date' => $start->copy(),
            'amount' => $plan->deposit_amount,
            'status' => Installment::STATUS_DUE,
        ]);

        // Weekly payments; the final one absorbs any rounding remainder so the
        // schedule sums exactly to the financed amount.
        $weeks = (int) $plan->weeks;
        $weekly = (float) $plan->weekly_amount;
        $financed = (float) $plan->financed_amount;
        $runningBefore = 0.0;

        for ($i = 1; $i <= $weeks; $i++) {
            $amount = $i === $weeks ? round($financed - $runningBefore, 2) : $weekly;
            $runningBefore = round($runningBefore + $amount, 2);
            $plan->installments()->create([
                'sequence' => $i,
                'kind' => Installment::KIND_WEEKLY,
                'due_date' => $start->copy()->addWeeks($i),
                'amount' => $amount,
                'status' => Installment::STATUS_DUE,
            ]);
        }

        $plan->start_date = $start->copy();
        $plan->status = InstallmentPlan::STATUS_ACTIVE;
        $plan->save();

        return $plan;
    }

    /** Record a single installment as paid, writing a receipt to the ledger. */
    public function recordPayment(Installment $installment, array $data = [], ?User $by = null): CaseFinancePayment
    {
        $plan = $installment->plan;

        $reference = $installment->kind === Installment::KIND_DEPOSIT
            ? 'Deposit'
            : 'Instalment '.$installment->sequence;

        $payment = CaseFinancePayment::create([
            'lead_id' => $plan->lead_id,
            'paid_at' => $data['paid_at'] ?? Carbon::now(),
            'amount' => $installment->amount,
            'method' => $data['method'] ?? null,
            'reference' => $data['reference'] ?? $reference,
            'recorded_by' => $by?->id,
        ]);

        $installment->status = Installment::STATUS_PAID;
        $installment->paid_at = $payment->paid_at;
        $installment->finance_payment_id = $payment->id;
        $installment->save();

        if ($plan->fresh()->isSettled()) {
            $plan->status = InstallmentPlan::STATUS_COMPLETED;
            $plan->save();
        }

        return $payment;
    }

    /** The live (draft or active) plan for a case, if any. */
    public function activeFor(Lead $lead): ?InstallmentPlan
    {
        return InstallmentPlan::where('lead_id', $lead->id)
            ->whereIn('status', [InstallmentPlan::STATUS_DRAFT, InstallmentPlan::STATUS_ACTIVE, InstallmentPlan::STATUS_COMPLETED])
            ->latest('id')
            ->first();
    }

    /** Compact plan + schedule for the UI. */
    public function summary(?InstallmentPlan $plan): ?array
    {
        if (! $plan) {
            return null;
        }

        return [
            'id' => $plan->id,
            'status' => $plan->status,
            'plan_months' => $plan->plan_months,
            'weeks' => $plan->weeks,
            'total_amount' => (float) $plan->total_amount,
            'deposit_percent' => (float) $plan->deposit_percent,
            'deposit_amount' => (float) $plan->deposit_amount,
            'inz_fee' => (float) $plan->inz_fee,
            'professional_fee' => (float) $plan->professional_fee,
            'discount_type' => $plan->discount_type ?? 'none',
            'discount_value' => (float) $plan->discount_value,
            'discount_amount' => (float) $plan->discount_amount,
            'admin_fee' => (float) $plan->admin_fee,
            'financed_amount' => (float) $plan->financed_amount,
            'weekly_amount' => (float) $plan->weekly_amount,
            'total_paid' => $plan->totalPaid(),
            'amount_owed' => $plan->amountOwed(),
            'start_date' => optional($plan->start_date)->toDateString(),
            'schedule' => $plan->installments->map(fn (Installment $i) => [
                'id' => $i->id,
                'sequence' => $i->sequence,
                'kind' => $i->kind,
                'due_date' => optional($i->due_date)->toDateString(),
                'amount' => (float) $i->amount,
                'status' => $i->isOverdue() ? 'overdue' : $i->status,
                'paid_at' => optional($i->paid_at)->toIso8601String(),
            ])->all(),
        ];
    }
}
