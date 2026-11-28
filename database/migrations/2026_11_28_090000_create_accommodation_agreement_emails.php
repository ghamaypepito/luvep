<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_agreement_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_agreement_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('status', 12)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_agreement_emails');
    }
};
