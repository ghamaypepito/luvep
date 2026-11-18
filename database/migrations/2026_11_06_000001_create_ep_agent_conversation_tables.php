<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ep_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('portal', 32);
            $table->foreignId('lead_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('title', 120)->default('New conversation');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'archived_at', 'id']);
        });
        Schema::create('ep_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ep_conversations')->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('content');
            $table->timestamp('created_at');
            $table->index(['conversation_id', 'id']);
        });
        Schema::create('ep_record_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('portal', 32);
            $table->timestamp('expires_at');
            $table->unique(['user_id', 'lead_id', 'portal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ep_record_grants');
        Schema::dropIfExists('ep_messages');
        Schema::dropIfExists('ep_conversations');
    }
};
