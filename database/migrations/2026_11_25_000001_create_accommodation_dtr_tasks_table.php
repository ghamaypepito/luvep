<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_dtr_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('status')->default('pending');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('completed_by_name')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_dtr_tasks');
    }
};
