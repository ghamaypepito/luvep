<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('immigration_employers', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name');
            $table->string('trading_name')->nullable();
            $table->string('employer_identifier', 64)->unique();
            $table->text('business_activity')->nullable();
            $table->string('accreditation_type', 32)->nullable();
            $table->date('accreditation_expires_on')->nullable();
            $table->unsignedInteger('approved_positions')->nullable();
            $table->timestamps();
        });

        Schema::create('immigration_job_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained('immigration_employers')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role_title');
            $table->string('stage', 32)->default('intake')->index();
            $table->json('details');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('immigration_job_checks');
        Schema::dropIfExists('immigration_employers');
    }
};
