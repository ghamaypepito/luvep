<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Do you know your Job Skills Level as per ANZSCO or NOL?" — a single
 * nullable column on every intake that has an employment section (Level 1–5 or
 * No). All dedicated tables (not the leads god-table).
 */
return new class extends Migration
{
    private array $tables = ['resident_intakes', 'work_intakes', 'student_intakes', 'visitor_intakes', 'family_intakes'];

    public function up(): void
    {
        foreach ($this->tables as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'job_skills_level')) {
                Schema::table($t, fn (Blueprint $table) => $table->string('job_skills_level', 20)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $t) {
            if (Schema::hasColumn($t, 'job_skills_level')) {
                Schema::table($t, fn (Blueprint $table) => $table->dropColumn('job_skills_level'));
            }
        }
    }
};
