<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitor intake: only the questions it lacks — birth certificate + face image
 * (Identity). Medical / police are NOT added; the Visitor intake already has
 * previous_police_certificate, previous_xray, inz_requested_medical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_intakes', function (Blueprint $table) {
            if (! Schema::hasColumn('visitor_intakes', 'birth_certificate_available')) {
                $table->string('birth_certificate_available', 10)->nullable();
                $table->text('birth_certificate_reason')->nullable();
                $table->boolean('face_image_consent')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('visitor_intakes', function (Blueprint $table) {
            $table->dropColumn(['birth_certificate_available', 'birth_certificate_reason', 'face_image_consent']);
        });
    }
};
