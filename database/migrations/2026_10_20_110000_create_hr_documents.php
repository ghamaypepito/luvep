<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_employee_id')->constrained()->restrictOnDelete();
            $t->string('title');
            $t->string('classification')->default('hr_private');
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });
        Schema::create('hr_document_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_document_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('storage_key')->unique();
            $t->string('original_filename');
            $t->string('mime', 100);
            $t->unsignedBigInteger('size');
            $t->string('sha256', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('released_at')->nullable();
            $t->unique(['hr_document_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_document_versions');
        Schema::dropIfExists('hr_documents');
    }
};
