<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagement_pack_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('pack_id', 100);
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at');
            $table->unique(['lead_id', 'pack_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_pack_archives');
    }
};
