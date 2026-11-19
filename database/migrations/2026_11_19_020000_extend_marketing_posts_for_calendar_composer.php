<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_posts', function (Blueprint $table) {
            $table->string('post_type', 12)->nullable();
            $table->text('first_comment')->nullable();
            $table->boolean('publish_now')->default(false);
            $table->index(['location_id', 'scheduled_at'], 'marketing_posts_location_scheduled_index');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_posts', function (Blueprint $table) {
            $table->dropIndex('marketing_posts_location_scheduled_index');
            $table->dropColumn(['post_type', 'first_comment', 'publish_now']);
        });
    }
};
