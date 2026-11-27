<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodation_rent_payments', function (Blueprint $table) {
            $table->date('received_on')->nullable();
        });
        Schema::create('accommodation_rental_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('accommodation_tenants')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16);
            $table->uuid('submission_key')->unique();
            $table->string('submission_hash', 64);
            $table->string('reference')->unique();
            $table->json('details');
            $table->string('pdf_path');
            $table->timestamps();
            $table->index(['tenant_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_rental_invoices');
        Schema::table('accommodation_rent_payments', fn (Blueprint $table) => $table->dropColumn('received_on'));
    }
};
