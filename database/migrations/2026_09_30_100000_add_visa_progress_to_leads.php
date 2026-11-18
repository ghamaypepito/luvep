<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INZ-style sub-status tracker for immigration cases in the Visa Lodge / Visa
 * Outcome stages. Stored as a single nullable JSON column (holds the activities
 * + their per-activity status history + the overall application_status label).
 *
 * ROW-SIZE SAFETY (the reason this is raw SQL, not a plain Schema::table add):
 * the `leads` god table's existing columns already exceed InnoDB's ~8126-byte
 * in-row limit on production MySQL. Adding ANY column rebuilds the table, which
 * fails with SQLSTATE 1118 ("Row size too large"); and shrinking columns one at
 * a time also fails, because each individual ALTER still passes through an
 * over-limit intermediate table. So — exactly like the nzer_number migration —
 * the fix is ONE atomic ALTER that MySQL evaluates only against the FINAL table:
 * ROW_FORMAT=DYNAMIC (long varchars + JSON stored off-page) + a batch of large,
 * non-searched columns moved to TEXT (exempt from the 8126 limit) + the JSON
 * column, all in a single statement. This migration is self-sufficient: it does
 * not rely on the earlier row-size migrations having succeeded on production.
 * Idempotent (hasColumn-guarded) and a no-op on sqlite (tests have no limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leads', 'visa_progress')) {
            return; // already added (e.g. a prior successful run)
        }

        if (DB::getDriverName() !== 'mysql') {
            // sqlite (tests) has no row-size limit — just add the column.
            Schema::table('leads', fn (Blueprint $t) => $t->json('visa_progress')->nullable()->after('stage_history'));

            return;
        }

        // Columns that are indexed can't become TEXT without a prefix length —
        // never touch those (and never touch the primary key).
        $indexed = collect(DB::select('SHOW INDEX FROM `leads`'))
            ->pluck('Column_name')->filter()->unique()->all();

        // Discover EVERY large, default-less, non-indexed VARCHAR and move it
        // off-page to TEXT (exempt from the 8126 in-row limit). This is schema-
        // adaptive, so it frees enough room even on a server where
        // ROW_FORMAT=DYNAMIC is a no-op, and regardless of how many wide columns
        // the god table has grown. Skipping defaulted columns (and keeping each
        // column's own nullability) keeps the change safe — no DEFAULT or NOT
        // NULL semantics are lost, and TEXT holds >= the old VARCHAR data.
        $wide = DB::select(
            "SELECT COLUMN_NAME AS name, IS_NULLABLE AS nullable FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'
               AND DATA_TYPE = 'varchar' AND CHARACTER_MAXIMUM_LENGTH >= 100
               AND COLUMN_DEFAULT IS NULL"
        );

        $clauses = ['ROW_FORMAT=DYNAMIC'];
        foreach ($wide as $col) {
            if (! in_array($col->name, $indexed, true)) {
                $null = $col->nullable === 'YES' ? 'NULL' : 'NOT NULL';
                $clauses[] = "MODIFY `{$col->name}` TEXT {$null}";
            }
        }
        $clauses[] = 'ADD COLUMN `visa_progress` JSON NULL AFTER `stage_history`';

        // One atomic ALTER — MySQL only evaluates the resulting (much smaller) table.
        DB::statement('ALTER TABLE `leads` '.implode(', ', $clauses));
    }

    public function down(): void
    {
        if (Schema::hasColumn('leads', 'visa_progress')) {
            Schema::table('leads', fn (Blueprint $t) => $t->dropColumn('visa_progress'));
        }
    }
};
