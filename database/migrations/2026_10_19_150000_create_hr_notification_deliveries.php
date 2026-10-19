<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_notification_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained('hr_events')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('channel', 16);
            $t->uuid('notification_id')->unique();
            $t->string('status', 16)->default('pending')->index();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->string('last_error')->nullable();
            $t->timestamps();
            $t->unique(['event_id', 'user_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_notification_deliveries');
    }
};
