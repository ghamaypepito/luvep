<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a structured discount + admin fee to the installment plan snapshot, so
 * the engagement total is: (professional_fee − discount_amount) + inz_fee +
 * admin_fee. `total_amount` stays the final computed total (existing reads keep
 * working); the component columns are kept for display on the plan, engagement
 * and invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->decimal('professional_fee', 10, 2)->default(0)->after('inz_fee');
            $table->string('discount_type', 10)->default('none')->after('professional_fee'); // none|percent|fixed
            $table->decimal('discount_value', 10, 2)->default(0)->after('discount_type');     // the % or $ staff entered
            $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_value');   // the computed $ off
            $table->decimal('admin_fee', 10, 2)->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->dropColumn(['professional_fee', 'discount_type', 'discount_value', 'discount_amount', 'admin_fee']);
        });
    }
};
