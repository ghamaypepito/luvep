<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('education_rsvps', function (Blueprint $table) {
            $table->string('attendance_source', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('education_rsvps', function (Blueprint $table) {
            $table->dropColumn('attendance_source');
        });
    }
};
