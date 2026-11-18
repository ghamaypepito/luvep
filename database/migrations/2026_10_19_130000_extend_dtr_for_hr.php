<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtr_entries', function (Blueprint $t) {
            $t->string('hr_status')->default('legacy')->index();
            $t->unsignedInteger('hr_version')->default(0);
            $t->string('work_location')->nullable();
            $t->unsignedInteger('meetings')->default(0);
            $t->unsignedInteger('people_assisted')->default(0);
            $t->text('blockers')->nullable();
            $t->text('tomorrow')->nullable();
            $t->string('declared_name')->nullable();
            $t->timestamp('submitted_at')->nullable();
        });
        Schema::create('hr_dtr_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dtr_entry_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('decision');
            $t->text('comment')->nullable();
            $t->unsignedInteger('version');
            $t->json('before_state')->nullable();
            $t->json('after_state')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('hr_weekly_signoffs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_employee_id')->constrained()->restrictOnDelete();
            $t->date('week_start');
            $t->json('revisions');
            $t->string('status')->default('submitted');
            $t->unsignedInteger('version')->default(0);
            $t->foreignId('signed_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('signed_at');
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_comment')->nullable();
            $t->timestamps();
            $t->unique(['hr_employee_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_weekly_signoffs');
        Schema::dropIfExists('hr_dtr_reviews');
        Schema::table('dtr_entries', fn (Blueprint $t) => $t->dropColumn(['hr_status', 'hr_version', 'work_location', 'meetings', 'people_assisted', 'blockers', 'tomorrow', 'declared_name', 'submitted_at']));
    }
};
