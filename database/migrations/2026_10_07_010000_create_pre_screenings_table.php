<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-screening — the vetting layer on top of a Visa Assessment, before a
 * potential client becomes an official case.
 *
 * One row per assessment entry (submitted intake OR draft/free Lead — a
 * polymorphic `subject`), carrying its own private token for the client-facing
 * /application/{token} link, plus the pre-screening state: the two consultation
 * bookings, the consent (+ audio) the client is sent and acknowledges, and the
 * Eligible / Converted / Declined / Skip outcome. Its own table — nothing is
 * added to the leads god table or the existing Assessment/pay-book funnel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_screenings', function (Blueprint $table) {
            $table->id();

            // Private credential for the /application/{token} client link.
            $table->string('token', 64)->unique();

            // The assessment entry this belongs to: an intake model (submitted)
            // or a Lead (draft / free assessment).
            $table->morphs('subject');

            // new → consultation → consent → eligible → converted | declined
            $table->string('status')->default('new')->index();

            // The two consultations are real bookings (meeting 1 = immigration
            // consultation, meeting 2 = consent). Nullable until booked.
            $table->unsignedBigInteger('consult_booking_id')->nullable();
            $table->unsignedBigInteger('consent_booking_id')->nullable();

            // The consent + key-matters audio sent to, and acknowledged by, the client.
            $table->timestamp('consent_sent_at')->nullable();
            $table->timestamp('consent_ack_at')->nullable();

            // Outcome — who + when, for the audit trail.
            $table->timestamp('eligible_at')->nullable();
            $table->unsignedBigInteger('eligible_by')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->unsignedBigInteger('converted_by')->nullable();
            $table->unsignedBigInteger('converted_lead_id')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->unsignedBigInteger('declined_by')->nullable();
            $table->text('decline_reason')->nullable();

            // "Skip these steps" — bypass the consultation/consent flow, logged.
            $table->timestamp('skip_at')->nullable();
            $table->unsignedBigInteger('skip_by')->nullable();
            $table->text('skip_reason')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_screenings');
    }
};
