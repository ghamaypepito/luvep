<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('education_event_registrations', function (Blueprint $table) {
            $table->string('school', 180)->nullable()->change();
            $table->string('goal', 100)->nullable()->change();
            $table->string('planning_timeline', 40)->nullable()->change();
            $table->string('education_level', 80)->nullable()->change();
        });
        Schema::table('education_visits', fn (Blueprint $table) => $table->boolean('time_confirmed')->default(false));
    }

    public function down(): void
    {
        Schema::table('education_visits', fn (Blueprint $table) => $table->dropColumn('time_confirmed'));
        // Retain nullable snapshots so rolling back the UI cannot discard collected registrations.
    }
};
