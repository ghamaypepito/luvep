<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_gas_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('accommodation_properties')->restrictOnDelete();
            $table->date('ordered_on');
            $table->unsignedInteger('bottles');
            $table->unsignedInteger('paid_cents');
            $table->uuid('submission_key')->unique();
            $table->timestamps();
            $table->index(['property_id', 'ordered_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_gas_orders');
    }
};
