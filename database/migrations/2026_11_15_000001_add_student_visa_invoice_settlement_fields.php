<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_receivables', function (Blueprint $table) {
            $table->boolean('internal_settlement_pending')->default(false);
        });
        Schema::table('case_finance_payments', function (Blueprint $table) {
            $table->boolean('finance_verified')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('case_finance_payments', function (Blueprint $table) {
            $table->dropColumn('finance_verified');
        });
        Schema::table('finance_receivables', function (Blueprint $table) {
            $table->dropColumn('internal_settlement_pending');
        });
    }
};
