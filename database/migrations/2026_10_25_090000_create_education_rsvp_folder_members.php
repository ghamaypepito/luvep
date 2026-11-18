<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_rsvp_folder_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('folder_id')->constrained('education_rsvp_folders')->cascadeOnDelete();
            $t->foreignId('education_rsvp_id')->constrained('education_rsvps')->cascadeOnDelete();
            $t->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['folder_id', 'education_rsvp_id'], 'education_rsvp_folder_member_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_rsvp_folder_members');
    }
};
