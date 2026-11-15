<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_screenings', function (Blueprint $table) {
            $table->timestamp('aewv_endorsed_at')->nullable();
            $table->foreignId('aewv_endorsed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pre_screenings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aewv_endorsed_by');
            $table->dropColumn('aewv_endorsed_at');
        });
    }
};
