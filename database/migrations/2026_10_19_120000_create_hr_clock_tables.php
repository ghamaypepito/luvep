<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', fn (Blueprint $t) => $t->unsignedInteger('clock_version')->default(0));
        Schema::table('dtr_entries', fn (Blueprint $t) => $t->string('time_source')->default('legacy'));
        Schema::create('hr_shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_employee_id')->constrained()->restrictOnDelete();
            $t->date('work_date');
            $t->string('timezone');
            $t->dateTime('started_at', 6);
            $t->dateTime('ended_at', 6)->nullable();
            $t->dateTime('scheduled_end_at', 6)->nullable();
            $t->json('schedule_snapshot')->nullable();
            $t->unsignedTinyInteger('active_guard')->nullable()->default(1);
            $t->dateTime('reminder_claimed_at')->nullable();
            $t->timestamps();
            $t->unique(['hr_employee_id', 'active_guard']);
            $t->index(['hr_employee_id', 'work_date']);
        });
        Schema::create('hr_breaks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_shift_id')->constrained()->restrictOnDelete();
            $t->dateTime('started_at', 6);
            $t->dateTime('ended_at', 6)->nullable();
            $t->unsignedTinyInteger('active_guard')->nullable()->default(1);
            $t->timestamps();
            $t->unique(['hr_shift_id', 'active_guard']);
        });
        Schema::create('hr_clock_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_employee_id')->constrained()->restrictOnDelete();
            $t->uuid('request_id');
            $t->string('action');
            $t->foreignId('hr_shift_id')->constrained()->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['hr_employee_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_clock_requests');
        Schema::dropIfExists('hr_breaks');
        Schema::dropIfExists('hr_shifts');
        Schema::table('dtr_entries', fn (Blueprint $t) => $t->dropColumn('time_source'));
        Schema::table('hr_employees', fn (Blueprint $t) => $t->dropColumn('clock_version'));
    }
};
