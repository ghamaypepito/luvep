<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Installment schedule rows — one per scheduled payment of an installment plan:
 * the upfront deposit (sequence 0, kind "deposit") then the weekly payments
 * (sequence 1..n, kind "weekly"). Staff mark each as paid, which also writes a
 * CaseFinancePayment receipt into the existing ledger (finance_payment_id links
 * the two). "Overdue" is derived from due_date vs today, not stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('installment_plan_id')->index();

            $table->unsignedSmallInteger('sequence'); // 0 = deposit, 1..n = weekly
            $table->string('kind')->default('weekly'); // deposit | weekly
            $table->date('due_date');
            $table->decimal('amount', 10, 2);

            // due -> paid (overdue is derived at read time from due_date).
            $table->string('status')->default('due')->index();
            $table->timestamp('paid_at')->nullable();

            // The ledger receipt created when this installment is marked paid.
            $table->unsignedBigInteger('finance_payment_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
