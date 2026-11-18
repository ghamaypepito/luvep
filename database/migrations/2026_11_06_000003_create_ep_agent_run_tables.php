<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ep_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('conversation_id')->constrained('ep_conversations')->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->unique()->constrained('ep_usage_reservations')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('portal', 32);
            $table->string('state', 24);
            $table->string('reason', 64)->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'idempotency_key']);
            $table->index(['portal', 'id']);
        });
        Schema::create('ep_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('conversation_id')->constrained('ep_conversations')->restrictOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('ep_runs')->restrictOnDelete();
            $table->string('type', 40);
            $table->timestamp('created_at');
            $table->index(['actor_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ep_events');
        Schema::dropIfExists('ep_runs');
    }
};
