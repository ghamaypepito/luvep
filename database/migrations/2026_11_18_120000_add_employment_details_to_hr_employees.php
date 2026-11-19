<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', fn (Blueprint $table) => $table->longText('employment_details')->nullable());
    }

    public function down(): void
    {
        Schema::table('hr_employees', fn (Blueprint $table) => $table->dropColumn('employment_details'));
    }
};
