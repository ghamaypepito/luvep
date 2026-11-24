<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editorial_articles', fn (Blueprint $table) => $table->json('featured_images')->nullable());
    }

    // Keep saved image selections if application code is rolled back.
    public function down(): void {}
};
