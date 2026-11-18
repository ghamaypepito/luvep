<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ep_budget_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedBigInteger('limit_micros');
            $table->unsignedBigInteger('spent_micros')->default(0);
            $table->unsignedBigInteger('held_micros')->default(0);
            $table->boolean('frozen')->default(false);
        });
        Schema::create('ep_usage_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('ep_budget_accounts')->restrictOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->string('state', 24);
            $table->unsignedBigInteger('upper_bound_micros');
            $table->unsignedBigInteger('actual_micros')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('model', 128)->nullable();
            $table->string('pricing_version', 128)->nullable();
            foreach (['input_tokens', 'output_tokens', 'cached_read_tokens', 'cached_write_tokens', 'tool_units'] as $unit) {
                $table->unsignedBigInteger($unit)->nullable();
            }
            // Snapshot exact unit prices; later config edits cannot rewrite historical costs.
            $table->json('price_snapshot')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ep_usage_reservations');
        Schema::dropIfExists('ep_budget_accounts');
    }
};
