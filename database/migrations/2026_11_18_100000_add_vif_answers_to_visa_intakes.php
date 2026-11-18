<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['work_intakes', 'student_intakes', 'visitor_intakes', 'resident_intakes', 'family_intakes'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->json('vif_answers')->nullable());
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('vif_answers'));
        }
    }
};
