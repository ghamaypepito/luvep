<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('education_event_registrations', function (Blueprint $table) {
            $table->foreignId('education_visit_id')->nullable()->constrained('education_visits')->nullOnDelete();
            $table->date('event_date')->nullable()->change();
            $table->string('event_time', 5)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('education_event_registrations', fn (Blueprint $table) => $table->dropConstrainedForeignId('education_visit_id'));
        // Keep nullable snapshots so rollback cannot discard valid TBC registrations.
    }
};
