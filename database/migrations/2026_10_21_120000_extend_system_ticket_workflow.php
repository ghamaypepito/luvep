<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_tickets', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('tracking_started_at')->nullable();
            $table->dateTime('archived_at')->nullable()->index();
            $table->string('waiting_on')->nullable();
            $table->string('topic')->nullable();
            $table->json('resolution_report')->nullable();
            $table->text('documentation_title')->nullable();
            $table->text('documentation_body')->nullable();
            $table->dateTime('documentation_published_at')->nullable();
            $table->unsignedInteger('response_target_hours')->nullable();
        });
        DB::table('system_tickets')->update(['tracking_started_at' => now()]);
        Schema::create('system_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('system_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_support');
            $table->timestamps();
            $table->index(['ticket_id', 'id']);
        });
        Schema::create('system_ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('system_tickets')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ticket_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_ticket_events');
        Schema::dropIfExists('system_ticket_messages');
        Schema::table('system_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn(['assigned_at', 'tracking_started_at', 'archived_at', 'waiting_on', 'topic', 'resolution_report', 'documentation_title', 'documentation_body', 'documentation_published_at', 'response_target_hours']);
        });
    }
};
