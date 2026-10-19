<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_employee_id')->constrained()->restrictOnDelete();
            $t->string('category');
            $t->text('subject');
            $t->uuid('request_id');
            $t->string('status')->default('open');
            $t->unsignedInteger('version')->default(0);
            $t->foreignId('assigned_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['hr_employee_id', 'request_id']);
        });
        Schema::create('hr_request_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->longText('body');
            $t->boolean('internal')->default(false);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_request_messages');
        Schema::dropIfExists('hr_requests');
    }
};
