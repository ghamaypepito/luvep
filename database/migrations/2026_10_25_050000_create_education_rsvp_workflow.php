<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_visits', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('school');
            $t->string('kind', 25);
            $t->string('city')->nullable();
            $t->string('activity');
            $t->string('mode')->default('in_person');
            $t->string('logo_path')->nullable();
            $t->date('proposed_date')->nullable();
            $t->date('event_date')->nullable();
            $t->string('event_time', 5)->nullable();
            $t->string('timezone')->default('Asia/Manila');
            $t->string('location')->nullable();
            $t->boolean('schedule_confirmed')->default(false);
            $t->unsignedInteger('schedule_version')->default(0);
            $t->string('status', 20)->default('open');
            $t->timestamps();
        });
        Schema::create('education_rsvps', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->uuid('submission_id')->unique();
            $t->string('payload_hash', 64);
            $t->foreignId('education_visit_id')->constrained()->restrictOnDelete();
            $t->string('full_name', 150);
            $t->string('name_key', 180)->index();
            $t->string('email', 254);
            $t->string('phone', 40)->nullable();
            $t->string('role', 100)->nullable();
            $t->boolean('email_reminders')->default(false);
            $t->boolean('future_invites')->default(false);
            $t->timestamp('consented_at');
            $t->timestamp('verified_at')->nullable();
            $t->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('linked_at')->nullable();
            $t->string('attendance_status', 20)->default('rsvp');
            $t->unsignedInteger('schedule_version_seen')->default(0);
            $t->timestamps();
            $t->unique(['education_visit_id', 'email']);
        });
        Schema::create('education_outreach', function (Blueprint $t) {
            $t->id();
            $t->string('event_key')->unique();
            $t->foreignId('education_rsvp_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('education_registration_id')->nullable()->constrained('education_event_registrations')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('channel', 20);
            $t->string('kind', 30);
            $t->unsignedInteger('schedule_version')->default(0);
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable()->index();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_outreach');
        Schema::dropIfExists('education_rsvps');
        Schema::dropIfExists('education_visits');
    }
};
