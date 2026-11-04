<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('tenant_id')->nullable()->constrained('accommodation_tenants')->cascadeOnDelete();
            $t->foreignId('root_id')->nullable()->constrained('media_folders')->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('media_folders')->cascadeOnDelete();
            $t->string('client_key')->nullable()->unique();
            $t->string('name', 120);
            $t->string('ancestry', 512)->default('/');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['parent_id', 'name']);
        });
        Schema::create('media_shares', function (Blueprint $t) {
            $t->id();
            $t->foreignId('folder_id')->constrained('media_folders')->cascadeOnDelete();
            $t->string('email', 254);
            $t->string('permission', 10);
            $t->string('token_hash', 64)->unique();
            $t->text('token');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('media_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('folder_id')->constrained('media_folders')->cascadeOnDelete();
            $t->string('original_name', 255);
            $t->string('file_path');
            $t->string('mime', 120);
            $t->unsignedBigInteger('size');
            $t->string('sha256', 64);
            $t->uuid('upload_key')->unique();
            $t->string('actor_key', 64);
            $t->string('initial_status', 20)->default('Submitted');
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('uploaded_share_id')->nullable()->constrained('media_shares')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('media_challenges', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('share_id')->constrained('media_shares')->cascadeOnDelete();
            $t->unsignedInteger('share_version');
            $t->string('session_hash', 64);
            $t->string('code_hash');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });
        Schema::create('media_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('folder_id')->constrained('media_folders')->cascadeOnDelete();
            $t->foreignId('file_id')->nullable()->constrained('media_files')->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('share_id')->nullable()->constrained('media_shares')->nullOnDelete();
            $t->string('kind', 40);
            $t->json('metadata')->nullable();
            $t->timestamp('created_at');
        });
        foreach (['lead_documents', 'tenant_documents'] as $name) {
            Schema::table($name, function (Blueprint $t) use ($name) {
                $t->foreignId('media_file_id')->nullable()->constrained('media_files')->restrictOnDelete();
                $t->string('media_target_key', 64)->nullable();
                $t->unique(['media_file_id', 'media_target_key'], $name.'_media_target_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['lead_documents', 'tenant_documents'] as $name) {
            Schema::table($name, function (Blueprint $t) use ($name) {
                $t->dropUnique($name.'_media_target_unique');
                $t->dropConstrainedForeignId('media_file_id');
                $t->dropColumn('media_target_key');
            });
        }
        foreach (['media_events', 'media_challenges', 'media_files', 'media_shares', 'media_folders'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
