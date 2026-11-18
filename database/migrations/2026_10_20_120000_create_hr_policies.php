<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_policies', function (Blueprint $t) {
            $t->id();
            $t->string('draft_title');
            $t->longText('draft_body');
            $t->unsignedInteger('revision')->default(0);
            $t->timestamps();
        });
        Schema::create('hr_policy_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_policy_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('title');
            $t->longText('body');
            $t->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('published_at');
            $t->unique(['hr_policy_id', 'version']);
        });
        Schema::create('hr_policy_acknowledgements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_policy_version_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamp('acknowledged_at');
            $t->unique(['hr_policy_version_id', 'user_id'], 'hr_policy_ack_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_policy_acknowledgements');
        Schema::dropIfExists('hr_policy_versions');
        Schema::dropIfExists('hr_policies');
    }
};
