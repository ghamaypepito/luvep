<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('location_id', 100)->index();
            $table->string('account_id', 200);
            $table->unsignedInteger('version')->default(1);
            $table->string('campaign', 200);
            $table->string('service', 200);
            $table->text('caption');
            $table->string('status', 40)->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime', 40)->nullable();
            $table->char('media_hash', 64)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedInteger('approved_version')->nullable();
            $table->char('approval_fingerprint', 64)->nullable();
            $table->text('review_note')->nullable();
            $table->string('ghl_post_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_posts');
    }
};
