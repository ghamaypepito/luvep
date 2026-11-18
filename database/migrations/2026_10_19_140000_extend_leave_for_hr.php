<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtr_leaves', function (Blueprint $t) {
            $t->unsignedInteger('hr_version')->default(0);
            $t->uuid('request_id')->nullable();
            $t->unique(['user_id', 'request_id']);
            $t->string('declared_name')->nullable();
            $t->string('sick_for')->nullable();
            $t->string('notified_via')->nullable();
            $t->timestamp('notified_at')->nullable();
            $t->string('certificate_status')->nullable();
            $t->boolean('team_checked')->default(false);
            $t->boolean('travel_acknowledged')->default(false);
            $t->boolean('auto_reply_planned')->default(false);
            $t->string('cover_person')->nullable();
            $t->text('handover')->nullable();
            $t->json('calendar_snapshot')->nullable();
            $t->date('response_due')->nullable();
            $t->boolean('emergency_required')->default(false);
            $t->foreignId('emergency_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('emergency_approved_at')->nullable();
        });
        Schema::create('hr_leave_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dtr_leave_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action');
            $t->text('comment')->nullable();
            $t->unsignedInteger('version');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_events');
        Schema::table('dtr_leaves', function (Blueprint $t) {
            $t->dropForeign(['emergency_approved_by']);
            $t->dropUnique(['user_id', 'request_id']);
            $t->dropColumn(['hr_version', 'request_id', 'declared_name', 'sick_for', 'notified_via', 'notified_at', 'certificate_status', 'team_checked', 'travel_acknowledged', 'auto_reply_planned', 'cover_person', 'handover', 'calendar_snapshot', 'response_due', 'emergency_required', 'emergency_approved_by', 'emergency_approved_at']);
        });
    }
};
