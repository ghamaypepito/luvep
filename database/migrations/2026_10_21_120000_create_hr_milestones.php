<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_milestones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_employee_id')->constrained('hr_employees');
            $t->string('kind', 30);
            $t->string('title', 200);
            $t->date('due_date');
            $t->foreignId('owner_user_id')->constrained('users');
            $t->boolean('employee_visible')->default(false);
            $t->text('private_note')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->unsignedInteger('deadline_revision')->default(1);
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['completed_at', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_milestones');
    }
};
