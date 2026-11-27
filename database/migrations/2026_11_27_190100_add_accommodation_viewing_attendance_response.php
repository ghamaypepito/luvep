<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodation_viewing_attendees', function (Blueprint $table) {
            $table->string('attendance_response', 20)->nullable();
            $table->timestamp('attendance_responded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accommodation_viewing_attendees', function (Blueprint $table) {
            $table->dropColumn(['attendance_response', 'attendance_responded_at']);
        });
    }
};
