<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-program verification overrides for the Program Verification module —
 * keyed by program id: { "<id>": { fee, school, intake, status, edited } }.
 * JSON (not VARCHAR) to stay clear of the leads-table in-row size limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        // NOTE: no `->after('proposed_program_reasons')` — that column is added by
        // a LATER migration (2026_09_14_add_program_reasons_to_proposals), so on a
        // fresh migrate it doesn't exist yet and the AFTER clause errors out.
        // Column position is cosmetic; drop the ordering dependency and guard for
        // idempotency so this is safe on both fresh and already-migrated DBs.
        if (Schema::hasColumn('leads', 'proposed_program_meta')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->json('proposed_program_meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('proposed_program_meta');
        });
    }
};
