<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_event_email_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('registration_id')->constrained('education_event_registrations')->cascadeOnDelete();
            $table->string('email');
            $table->string('ghl_contact_id')->nullable();
            $table->timestamp('follow_up_confirmed_at')->nullable();
            $table->timestamp('newsletter_confirmed_at')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('exited_at')->nullable();
            $table->timestamp('newsletter_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_event_email_subscriptions');
    }
};
