<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_articles', function (Blueprint $table) {
            $table->id();
            $table->string('topic_key', 32)->nullable()->unique();
            $table->date('planned_date')->nullable()->index();
            $table->string('pillar', 32)->nullable();
            $table->string('title', 200);
            $table->string('slug', 180)->unique();
            $table->text('summary')->nullable();
            $table->longText('body_html')->nullable();
            $table->string('primary_keyword', 180)->nullable();
            $table->string('meta_title', 100)->nullable();
            $table->string('meta_description', 220)->nullable();
            $table->string('author', 120)->nullable();
            $table->text('image_url')->nullable();
            $table->string('image_alt', 250)->nullable();
            $table->json('brief')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('approved_revision')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('published_slug', 180)->nullable()->unique();
            $table->json('published')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('published_updated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_articles');
    }
};
