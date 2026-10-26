<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror the Resident intake's extra processing questions onto the Work intake
 * where the matching section exists: birth certificate + face image (Identity),
 * medical certificate questions (Health) and police certificate (Character).
 * All nullable; dedicated table (not the leads god-table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_intakes', function (Blueprint $table) {
            if (! Schema::hasColumn('work_intakes', 'birth_certificate_available')) {
                $table->string('birth_certificate_available', 10)->nullable();
                $table->text('birth_certificate_reason')->nullable();
                $table->boolean('face_image_consent')->nullable();
                $table->string('medical_cert_recent', 10)->nullable();
                $table->string('medical_cert_submitted_last', 10)->nullable();
                $table->string('medical_cert_type')->nullable();
                $table->string('police_certificate_available', 10)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_intakes', function (Blueprint $table) {
            $table->dropColumn([
                'birth_certificate_available', 'birth_certificate_reason', 'face_image_consent',
                'medical_cert_recent', 'medical_cert_submitted_last', 'medical_cert_type',
                'police_certificate_available',
            ]);
        });
    }
};
