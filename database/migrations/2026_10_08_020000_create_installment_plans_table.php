<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Installment payment plans — the "pay later" terms for a case.
 *
 * When a client pays later, the fee is paid WEEKLY across a staff-chosen plan
 * (length in config('immigration.installments.plan_presets')). An upfront
 * deposit (a staff-set % that covers the INZ application fee) is taken first,
 * then the remaining balance is split evenly into the weekly installments
 * (the schedule rows live in `installments`). Attaches to the case/working
 * Lead by lead_id — it rides along when the pre-screening converts to a case.
 *
 * Its own table — nothing added to the leads god table. Money is decimal; the
 * derived figures (deposit/weekly) are stored so the agreed schedule is fixed
 * even if a fee later changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->index();

            // pay_now = full amount, no schedule. pay_later = weekly plan below.
            $table->string('payment_type')->default('pay_later');

            // Plan length + derived weekly count (from the config preset chosen).
            $table->unsignedSmallInteger('plan_months')->nullable();
            $table->unsignedSmallInteger('weeks')->nullable();

            // Agreed figures (GST-inclusive, snapshotted). The deposit covers the
            // INZ fee; financed_amount = total - deposit, split across `weeks`.
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('deposit_percent', 5, 2)->nullable();
            $table->decimal('deposit_amount', 10, 2)->nullable();
            $table->decimal('inz_fee', 10, 2)->nullable();
            $table->decimal('financed_amount', 10, 2)->nullable();
            $table->decimal('weekly_amount', 10, 2)->nullable();

            $table->date('start_date')->nullable();
            $table->string('currency', 3)->default('NZD');

            // draft -> active -> completed | cancelled
            $table->string('status')->default('draft')->index();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_plans');
    }
};
