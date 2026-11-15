<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('submission_key')->unique();
            $table->string('submission_hash', 64);
            $table->foreignId('tenant_id')->constrained('accommodation_tenants')->restrictOnDelete();
            $table->foreignId('property_id')->constrained('accommodation_properties')->restrictOnDelete();
            $table->foreignId('folder_id')->unique()->constrained('media_folders')->restrictOnDelete();
            $table->foreignId('invoice_file_id')->constrained('media_files')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 64)->unique();
            $table->string('tenant_name', 200);
            $table->text('property_address');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->decimal('total', 12, 2);
            $table->timestamps();
            $table->index(['property_id', 'issue_date']);
            $table->index(['tenant_id', 'issue_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_purchase_invoices');
    }
};
