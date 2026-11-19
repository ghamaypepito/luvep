<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_study_plans', fn (Blueprint $table) => $table->json('program_intakes')->nullable());
    }

    public function down(): void
    {
        Schema::table('lead_study_plans', fn (Blueprint $table) => $table->dropColumn('program_intakes'));
    }
};
