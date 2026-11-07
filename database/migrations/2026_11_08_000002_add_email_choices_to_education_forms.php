<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['education_rsvps', 'education_event_registrations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('email_follow_up')->default(false);
                $table->boolean('email_newsletter')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['education_rsvps', 'education_event_registrations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['email_follow_up', 'email_newsletter']);
            });
        }
    }
};
