<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_viewing_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->nullable()->constrained('accommodation_properties')->nullOnDelete();
            $table->string('property_address')->nullable();
            $table->date('viewing_date');
            $table->string('start_time', 5);
            $table->unsignedSmallInteger('capacity');
            $table->boolean('blocked')->default(false);
            $table->timestamps();

            $table->unique(['viewing_date', 'start_time'], 'accommodation_viewing_crew_slot_unique');
            $table->index(['property_id', 'viewing_date']);
        });

        Schema::create('accommodation_viewing_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('viewing_slot_id')->constrained('accommodation_viewing_slots')->restrictOnDelete();
            $table->string('name');
            $table->string('email', 191);
            $table->string('mobile', 40);
            $table->string('status', 20)->default('Confirmed');
            $table->timestamps();

            $table->unique(['viewing_slot_id', 'email'], 'accommodation_viewing_slot_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_viewing_attendees');
        Schema::dropIfExists('accommodation_viewing_slots');
    }
};
