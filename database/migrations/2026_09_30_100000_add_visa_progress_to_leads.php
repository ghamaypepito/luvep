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
    /** Large, free-text / link / misc columns safe to store off-page as TEXT
     *  (never searched or indexed) — mirrors the nzer_number batch. */
    private array $toText = [
        'other_names', 'preferred_name', 'place_of_birth',
        'residence_city', 'residence_state',
        'residence_address_line_1', 'residence_address_line_2',
        'passport_path', 'passport_issuing_country',
        'current_nz_visa_type', 'previous_nz_visa_type',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
        'client_info_link', 'call_update_form_link',
        'prescreened_by', 'goal_setting_by',
        'student_school', 'student_gdrive_link', 'gdrive_folder_id',
        'preferred_course', 'preferred_qualification_level',
        'preferred_city_of_study', 'preferred_intake', 'target_institution',
        'current_employer_name', 'current_position_title',
        'current_employer_country', 'current_employer_email',
        'nz_professional_registration_body',
        'highest_qualification', 'highest_qualification_field',
        'highest_qualification_country', 'nzqa_assessment_level',
    ];

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

        $clauses = ['ROW_FORMAT=DYNAMIC'];
        foreach ($this->toText as $col) {
            if (Schema::hasColumn('leads', $col)) {
                $clauses[] = "MODIFY `{$col}` TEXT NULL";
            }
        }
        $clauses[] = 'ADD COLUMN `visa_progress` JSON NULL AFTER `stage_history`';

        // One atomic ALTER — MySQL evaluates only the resulting (much smaller) table.
        DB::statement('ALTER TABLE `leads` '.implode(', ', $clauses));
    }

    public function down(): void
    {
        if (Schema::hasColumn('leads', 'visa_progress')) {
            Schema::table('leads', fn (Blueprint $t) => $t->dropColumn('visa_progress'));
        }
    }
};
