<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodation_properties', function (Blueprint $table) {
            $table->json('room_register')->nullable();
        });
        Schema::table('accommodation_tenants', function (Blueprint $table) {
            $table->json('assigned_room_ids')->nullable();
            $table->date('residency_started_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accommodation_tenants', function (Blueprint $table) {
            $table->dropColumn(['assigned_room_ids', 'residency_started_on']);
        });
        Schema::table('accommodation_properties', function (Blueprint $table) {
            $table->dropColumn('room_register');
        });
    }
};
