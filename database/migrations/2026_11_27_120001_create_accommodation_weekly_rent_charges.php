<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_weekly_rent_charges', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained('accommodation_tenants')->cascadeOnDelete();
            $table->date('week_start');
            $table->unsignedBigInteger('property_id')->nullable();
            $table->decimal('due_nzd', 11, 2);
            $table->unique(['tenant_id', 'week_start']);
            $table->index('week_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_weekly_rent_charges');
    }
};
