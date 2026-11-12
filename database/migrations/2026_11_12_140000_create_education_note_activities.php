<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_note_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->nullable()->unique()->constrained('lead_notes')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->string('activity', 20);
            $table->boolean('is_reply')->default(false);
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->index(['user_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_note_activities');
    }
};
