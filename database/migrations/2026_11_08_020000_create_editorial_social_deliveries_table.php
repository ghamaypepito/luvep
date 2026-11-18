<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'editorial_social_deliveries';

        // MySQL can retain the table if a later index statement fails.
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->foreignId('editorial_article_id')->constrained()->cascadeOnDelete();
                $table->string('account_id', 120);
                $table->json('payload');
                $table->string('status', 24)->default('pending');
                $table->boolean('waiting_for_connection')->default(false);
                $table->string('provider_post_id')->nullable();
                $table->timestamps();
            });
        }

        $destination = ['editorial_article_id', 'account_id'];
        if (! Schema::hasIndex($tableName, $destination, 'unique')) {
            Schema::table($tableName, fn (Blueprint $table) => $table->unique($destination, 'editorial_delivery_destination_unique'));
        }
        if (! Schema::hasIndex($tableName, ['status'])) {
            Schema::table($tableName, fn (Blueprint $table) => $table->index('status'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_social_deliveries');
    }
};
