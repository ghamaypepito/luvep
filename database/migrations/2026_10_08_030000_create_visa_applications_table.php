<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visa applications registry — links the separate case Leads a client holds as
 * they apply for one visa after another.
 *
 * The system models each visa as its OWN case (Lead) — reusing the existing
 * per-case machinery (checklist, financials, stage, process chain). This table
 * records, per case Lead, where it sits in the client's sequence (Visa 1, 2, …),
 * which case it descends from, and whether it is still open or has been closed
 * (e.g. once the visa is approved and the next application begins). A closed row
 * makes its case read-only. Nothing is added to the leads god table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visa_applications', function (Blueprint $table) {
            $table->id();
            // The case Lead this row describes (one row per case).
            $table->unsignedBigInteger('lead_id')->unique();
            // The first application in the client's chain (for grouping/switcher).
            $table->unsignedBigInteger('root_lead_id')->index();
            // The immediately preceding visa's case Lead, if any.
            $table->unsignedBigInteger('previous_lead_id')->nullable();

            $table->unsignedSmallInteger('sequence')->default(1); // Visa 1, 2, …
            $table->string('visa_type')->nullable();              // label snapshot

            // active -> closed (closed = locked; the next application has begun)
            $table->string('status')->default('active')->index();
            $table->string('outcome')->nullable();                // e.g. "Approved Visa"

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visa_applications');
    }
};
