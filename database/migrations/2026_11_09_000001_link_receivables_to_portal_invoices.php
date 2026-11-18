<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_receivables', function (Blueprint $table) {
            $table->foreignId('invoice_document_id')->nullable()->unique()->constrained('lead_documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_receivables', function (Blueprint $table) {
            $table->dropForeign(['invoice_document_id']);
            $table->dropUnique(['invoice_document_id']);
            $table->dropColumn('invoice_document_id');
        });
    }
};
