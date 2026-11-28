<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodation_eoi_submissions', function (Blueprint $table) {
            $table->timestamp('registration_reviewed_at')->nullable();
            $table->decimal('verified_move_in_amount_nzd', 12, 2)->nullable();
            $table->unsignedBigInteger('move_in_invoice_id')->nullable();
            $table->foreignId('viewing_attendee_id')->nullable()->constrained('accommodation_viewing_attendees')->nullOnDelete();
            $table->timestamp('welcome_email_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accommodation_eoi_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('viewing_attendee_id');
            $table->dropColumn(['registration_reviewed_at', 'verified_move_in_amount_nzd', 'move_in_invoice_id', 'welcome_email_sent_at']);
        });
    }
};
