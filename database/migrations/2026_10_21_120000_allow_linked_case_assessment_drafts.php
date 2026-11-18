<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Draft invitations know only the case identity. Public forms continue
        // to enforce their existing submission validation before accepting data.
        foreach (['work_intakes', 'student_intakes', 'visitor_intakes', 'family_intakes'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->date('dob')->nullable()->change();
                $table->string('phone')->nullable()->change();
            });
        }
        Schema::table('resident_intakes', function (Blueprint $table) {
            foreach (['phone', 'nationality', 'passport_number', 'issuing_country', 'current_visa_type', 'job_title', 'employment_type', 'highest_qualification', 'english_evidence', 'include_family'] as $field) {
                $table->string($field)->nullable()->change();
            }
            foreach (['dob', 'passport_expiry', 'current_visa_expiry', 'nz_arrival_date', 'employment_start'] as $field) {
                $table->date($field)->nullable()->change();
            }
            $table->decimal('hourly_rate', 8, 2)->nullable()->change();
            $table->decimal('nz_skilled_years', 5, 1)->nullable()->change();
            $table->decimal('total_skilled_years', 5, 1)->nullable()->change();
        });
        Schema::table('assessments', function (Blueprint $table) {
            $table->timestamp('invitation_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assessments', fn (Blueprint $table) => $table->dropColumn('invitation_sent_at'));
        // Retain nullable intake fields: restoring NOT NULL after drafts exist
        // would require inventing personal data or deleting those drafts.
    }
};
