<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_social_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('editorial_article_id')->constrained()->cascadeOnDelete();
            $table->string('account_id', 120);
            $table->json('payload');
            $table->string('status', 24)->default('pending')->index();
            $table->boolean('waiting_for_connection')->default(false);
            $table->string('provider_post_id')->nullable();
            $table->timestamps();
            $table->unique(['editorial_article_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_social_deliveries');
    }
};
