<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Working lead" for a pre-screening — the Lead that holds a potential client's
 * document uploads BEFORE they convert to a case.
 *
 * Documents in this system hang off a Lead (leads.id). A pre-screening's subject
 * is often a bare intake with no Lead yet (a Lead is only created on conversion),
 * so client uploads need somewhere to live in the meantime. This nullable link
 * points at a lightweight, non-case Lead created lazily on first upload. It is
 * distinct from `converted_lead_id`, which only fills once the case is made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_screenings', function (Blueprint $table) {
            $table->unsignedBigInteger('lead_id')->nullable()->after('subject_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('pre_screenings', function (Blueprint $table) {
            $table->dropColumn('lead_id');
        });
    }
};
