<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_dtr_acknowledgements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dtr_entry_id')->constrained()->restrictOnDelete();
            $t->foreignId('head_user_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('report_version');
            $t->unsignedInteger('version')->default(1);
            $t->string('status')->default('pending');
            $t->text('reason');
            $t->json('snapshot');
            $t->json('changes');
            $t->timestamp('submitted_at');
            $t->timestamp('decided_at')->nullable();
            $t->text('comment')->nullable();
            $t->unique(['dtr_entry_id', 'report_version']);
            $t->index(['head_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_dtr_acknowledgements');
    }
};
