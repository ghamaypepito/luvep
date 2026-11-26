<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_posts', function (Blueprint $table) {
            $table->text('brief')->nullable();
        });
        Schema::create('social_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('file_path')->nullable();
            $table->string('mime', 80)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->char('sha256', 64)->nullable();
            $table->text('caption')->nullable();
            $table->string('post_type', 12)->default('post');
            $table->uuid('source_post_id')->nullable();
            $table->unsignedInteger('source_version')->nullable();
            $table->uuid('upload_key')->nullable()->unique();
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();
            $table->unique(['source_post_id', 'source_version']);
        });
    }

    // Retain archived assets and briefs when application code is rolled back.
    public function down(): void {}
};
