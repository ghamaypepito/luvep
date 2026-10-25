<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_rsvp_folders', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80)->unique();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('education_rsvp_saved_lists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('folder_id')->constrained('education_rsvp_folders')->restrictOnDelete();
            $t->string('name', 100);
            $t->json('filters');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['folder_id', 'name']);
        });
        DB::table('education_rsvp_folders')->insert(['name' => 'Attendance lists', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('education_rsvp_saved_lists');
        Schema::dropIfExists('education_rsvp_folders');
    }
};
