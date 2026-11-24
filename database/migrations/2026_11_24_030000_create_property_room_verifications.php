<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_room_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->unique()->constrained('accommodation_properties')->cascadeOnDelete();
            $table->unsignedInteger('total_rooms');
            $table->unsignedInteger('occupied');
            $table->unsignedInteger('vacant');
            $table->unsignedInteger('unavailable');
            $table->date('observed_on');
            $table->text('source_ref');
            $table->text('vacancy_notes')->nullable();
            $table->string('tenancy_fingerprint', 64);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Retain staff verification data on application rollback.
    }
};
