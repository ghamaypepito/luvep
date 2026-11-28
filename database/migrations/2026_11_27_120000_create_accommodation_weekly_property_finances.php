<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_weekly_property_finances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('accommodation_properties')->restrictOnDelete();
            $table->date('week_start');
            $table->string('category', 16);
            $table->decimal('revenue_nzd', 10, 2)->nullable();
            $table->decimal('cost_nzd', 10, 2)->nullable();
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->unique(['week_start', 'category', 'property_id'], 'accommodation_finance_week_property_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_weekly_property_finances');
    }
};
