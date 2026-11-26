<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('partner_activity_records')) {
            Schema::create('partner_activity_records', function (Blueprint $table) {
                $table->id();
                $table->string('source_key', 80)->unique();
                $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
                $table->string('category', 30);
                $table->date('activity_on');
                $table->string('title', 255);
                $table->string('status', 40);
                $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('lead_id')->nullable()->constrained('leads')->restrictOnDelete();
                $table->date('follow_up_on')->nullable();
                $table->json('details')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->index(['agent_id', 'activity_on']);
                $table->index(['category', 'follow_up_on']);
            });
        }
        if (! Schema::hasTable('partner_activity_files')) {
            Schema::create('partner_activity_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('activity_id')->constrained('partner_activity_records')->restrictOnDelete();
                $table->string('name', 255);
                $table->string('path', 255);
                $table->string('mime', 120);
                $table->unsignedBigInteger('size');
                $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Retain recorded partnership history and private attachments on code rollback.
    }
};
