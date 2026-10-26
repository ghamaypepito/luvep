<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency markers for the onboarding reminder sweep: once a sign / choose
 * reminder has been emailed for an agreement, the marker is stamped so a re-run
 * of immigration:onboarding-reminders does not send it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreements', function (Blueprint $table) {
            $table->timestamp('sign_reminded_at')->nullable()->after('signed_at');
            $table->timestamp('choose_reminded_at')->nullable()->after('sign_reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('agreements', function (Blueprint $table) {
            $table->dropColumn(['sign_reminded_at', 'choose_reminded_at']);
        });
    }
};
