<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_equipment', fn (Blueprint $table) => $table->string('source')->default('hr_issued'));
    }

    public function down(): void
    {
        Schema::table('hr_equipment', fn (Blueprint $table) => $table->dropColumn('source'));
    }
};
