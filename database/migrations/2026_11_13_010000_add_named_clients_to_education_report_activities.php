<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('education_report_activities', function (Blueprint $table) {
            $table->string('client_name', 160)->nullable();
            $table->string('reported_status', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('education_report_activities', function (Blueprint $table) {
            $table->dropColumn(['client_name', 'reported_status']);
        });
    }
};
