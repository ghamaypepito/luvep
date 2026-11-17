<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_replacements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_receivable_id')->constrained()->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_number', 60);
            $table->string('previous_reference', 60)->index();
            $table->string('previous_path');
            $table->string('previous_filename');
            $table->string('previous_hash', 64);
            $table->string('replacement_number', 40);
            $table->string('replacement_reference', 40)->unique();
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('invoice_replacements') && DB::table('invoice_replacements')->exists()) {
            throw new RuntimeException('Invoice replacement history must be retained.');
        }
        Schema::dropIfExists('invoice_replacements');
    }
};
