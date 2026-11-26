<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_applications', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('draft_version')->default(0);
        });
        // Every pre-existing application was submitted through the complete form.
        DB::table('affiliate_applications')->update(['submitted_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('affiliate_applications', fn (Blueprint $table) => $table->dropColumn(['submitted_at', 'draft_version']));
    }
};
