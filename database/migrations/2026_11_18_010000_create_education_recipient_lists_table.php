<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_recipient_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('mode', 10);
            $table->json('filters');
            $table->json('lead_ids');
            $table->timestamps();
            $table->unique(['created_by', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_recipient_lists');
    }
};
