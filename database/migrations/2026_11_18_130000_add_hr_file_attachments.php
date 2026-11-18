<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_documents', fn (Blueprint $table) => $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete());
        Schema::table('hr_policies', fn (Blueprint $table) => $table->json('draft_attachment')->nullable());
        Schema::table('hr_policy_versions', fn (Blueprint $table) => $table->json('attachment')->nullable());
    }

    public function down(): void
    {
        Schema::table('hr_policy_versions', fn (Blueprint $table) => $table->dropColumn('attachment'));
        Schema::table('hr_policies', fn (Blueprint $table) => $table->dropColumn('draft_attachment'));
        Schema::table('hr_documents', function (Blueprint $table) {
            $table->dropForeign(['submitted_by']);
            $table->dropColumn('submitted_by');
        });
    }
};
