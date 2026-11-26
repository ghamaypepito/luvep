<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_workspace_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->foreignId('program_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('data');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['school_id', 'kind']);
        });
        Schema::create('school_workspace_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_workspace_record_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Retain staff-entered history and private-file metadata on code rollback.
    }
};
