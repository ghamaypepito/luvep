<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Potential Cases — the onboarding/vetting pipeline that sits BEFORE the
 * official immigration case pipeline. A new client is a "potential case" while
 * staff run their onboarding checks: two required consultations (each logged
 * with a note + the staff who attended), a standard "key matters" audio the
 * client must acknowledge, and the initial written agreement. Signing is gated
 * on the two meetings (a licensed adviser can override with a reason). Passing
 * promotes it to an official case.
 *
 * Its own table — nothing is added to the leads god table. A lead is a
 * potential case iff it has an active row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('potential_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->unique()->constrained()->cascadeOnDelete();

            // onboarding → signed → promoted | declined
            $table->string('status')->default('onboarding')->index();

            // The two required onboarding meetings, keyed "1"/"2":
            // { at, note, staff: [{id,name}], logged_by: {id,name}, logged_at }
            $table->json('meetings')->nullable();

            // Standard "key matters" audio — staff logs the client acknowledged it.
            $table->timestamp('audio_ack_at')->nullable();
            $table->foreignId('audio_ack_by')->nullable()->constrained('users')->nullOnDelete();

            // Initial written agreement (lives here, not on the official pipeline).
            $table->timestamp('agreement_sent_at')->nullable();
            $table->timestamp('agreement_signed_at')->nullable();

            // Licensed-adviser override of the two-meeting gate.
            $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->timestamp('override_at')->nullable();

            // Promotion to an official case — who (a staff member, or the System).
            $table->foreignId('promoted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('promoted_by_system')->default(false);
            $table->timestamp('promoted_at')->nullable();

            // Didn't pass onboarding.
            $table->foreignId('declined_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decline_reason')->nullable();
            $table->timestamp('declined_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('potential_cases');
    }
};
