<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_checklist_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('kind');
            $t->longText('tasks');
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
        });
        Schema::create('hr_checklists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('hr_checklist_template_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('kind');
            $t->longText('template_snapshot');
            $t->date('due_date');
            $t->unsignedTinyInteger('active_guard')->nullable()->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['hr_employee_id', 'kind', 'active_guard'], 'hr_checklist_active_unique');
        });
        Schema::create('hr_checklist_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_checklist_id')->constrained()->restrictOnDelete();
            $t->string('title');
            $t->string('kind')->default('general');
            $t->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $t->boolean('employee_visible')->default(false);
            $t->text('note')->nullable();
            $t->text('private_note')->nullable();
            $t->date('due_date');
            $t->unsignedInteger('version')->default(0);
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('hr_equipment', function (Blueprint $t) {
            $t->id();
            $t->string('asset_tag', 80)->unique();
            $t->string('description', 500);
            $t->foreignId('hr_employee_id')->constrained()->restrictOnDelete();
            $t->string('status')->default('returned');
            $t->unsignedInteger('version')->default(0);
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('returned_at')->nullable();
            $t->text('condition')->nullable();
            $t->timestamps();
        });
        Schema::create('hr_equipment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_equipment_id')->constrained('hr_equipment')->restrictOnDelete();
            $t->foreignId('hr_employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action');
            $t->string('asset_tag', 80);
            $t->string('description', 500);
            $t->text('condition')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['hr_equipment_events', 'hr_equipment', 'hr_checklist_tasks', 'hr_checklists', 'hr_checklist_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
