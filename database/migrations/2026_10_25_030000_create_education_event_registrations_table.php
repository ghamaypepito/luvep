<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_event_registrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('submission_id')->unique();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('payload_hash', 64);
            $table->string('event_name', 180);
            $table->date('event_date');
            $table->string('event_time', 5);
            $table->string('event_timezone', 50);
            $table->string('event_match_status', 20)->default('pending');
            $table->string('country', 100);
            $table->string('school', 180);
            $table->string('goal', 100);
            $table->string('planning_timeline', 40);
            $table->string('education_level', 80);
            $table->json('contact_details');
            $table->timestamp('consented_at');
            $table->unsignedBigInteger('welcome_log_id')->nullable();
            $table->json('notified_user_ids')->nullable();
            $table->text('preparation_notes')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('outreach_completed_at')->nullable()->index();
            $table->timestamps();
            $table->index(['event_date', 'school']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_event_registrations');
    }
};
