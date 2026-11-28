<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->json('compose_details')->nullable();
        });
        Schema::table('accommodation_properties', function (Blueprint $table) {
            $table->string('property_owner_name')->nullable();
            $table->string('property_owner_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accommodation_properties', function (Blueprint $table) {
            $table->dropColumn(['property_owner_name', 'property_owner_email']);
        });
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropColumn('compose_details');
        });
    }
};
