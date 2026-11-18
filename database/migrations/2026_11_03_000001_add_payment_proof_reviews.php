<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_finance_payments', function (Blueprint $table) {
            $table->foreignId('invoice_document_id')->nullable()->constrained('lead_documents')->restrictOnDelete();
            $table->char('currency', 3)->nullable();
        });
        Schema::create('payment_proof_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained('lead_documents')->restrictOnDelete();
            $table->foreignId('invoice_document_id')->constrained('lead_documents')->restrictOnDelete();
            $table->foreignId('finance_payment_id')->nullable()->constrained('case_finance_payments')->restrictOnDelete();
            $table->boolean('supporting')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proof_reviews');
        Schema::table('case_finance_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_document_id');
            $table->dropColumn('currency');
        });
    }
};
