<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodation_agreements', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->constrained('accommodation_properties')->restrictOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('accommodation_tenants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accommodation_agreements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropConstrainedForeignId('property_id');
        });
    }
};
