<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra questions staff asked the Resident assessment to capture (from the VIF
 * annotations): birth certificate availability, a face-image consent, the
 * employer name, and the medical / police certificate questions. All nullable
 * so the funnel is never blocked; the related documents stay optional uploads.
 * resident_intakes is a dedicated table (not the leads god-table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resident_intakes', function (Blueprint $table) {
            // Section A — Personal Details. Yes/No stored as a short string
            // (matching the form's existing include_family convention), so the
            // conditional required_if compares cleanly.
            $table->string('birth_certificate_available', 10)->nullable()->after('nationality');
            $table->text('birth_certificate_reason')->nullable()->after('birth_certificate_available');
            // Section B — Passport & Visa (acknowledgement checkbox → boolean)
            $table->boolean('face_image_consent')->nullable()->after('issuing_country');
            // Section C — Employment
            $table->string('employer_name')->nullable()->after('job_title');
            // Section G — Additional Information
            $table->string('medical_cert_recent', 10)->nullable()->after('character_health_disclosure');
            $table->string('medical_cert_submitted_last', 10)->nullable()->after('medical_cert_recent');
            $table->string('medical_cert_type')->nullable()->after('medical_cert_submitted_last');
            $table->string('police_certificate_available', 10)->nullable()->after('medical_cert_type');
        });
    }

    public function down(): void
    {
        Schema::table('resident_intakes', function (Blueprint $table) {
            $table->dropColumn([
                'birth_certificate_available', 'birth_certificate_reason', 'face_image_consent',
                'employer_name', 'medical_cert_recent', 'medical_cert_submitted_last',
                'medical_cert_type', 'police_certificate_available',
            ]);
        });
    }
};
