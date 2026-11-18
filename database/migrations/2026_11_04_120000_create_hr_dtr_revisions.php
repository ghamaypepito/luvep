<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_dtr_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dtr_entry_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('action');
            $t->json('changes');
            $t->timestamp('created_at');
            $t->unique(['dtr_entry_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_dtr_revisions');
    }
};
