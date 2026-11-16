<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_school_mailings', function (Blueprint $t) {
            $t->id();
            $t->uuid('submission_id')->unique();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('name', 160);
            $t->json('filters');
            $t->json('template_snapshot');
            $t->timestamps();
        });
        Schema::create('education_school_mail_recipients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mailing_id')->constrained('education_school_mailings')->cascadeOnDelete();
            $t->string('email', 254);
            $t->string('name', 180);
            $t->json('source_refs');
            $t->string('status', 20)->default('pending');
            $t->text('detail')->nullable();
            $t->unsignedBigInteger('message_log_id')->nullable();
            $t->timestamps();
            $t->unique(['mailing_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_school_mail_recipients');
        Schema::dropIfExists('education_school_mailings');
    }
};
