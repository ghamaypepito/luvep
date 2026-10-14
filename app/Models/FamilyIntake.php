<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Family Visa (Partner or Child) intake — the public Family Visa Information
 * Form assessment. Mirrors the other intake models.
 */
class FamilyIntake extends Model
{
    protected $fillable = [
        'intake_id', 'status', 'edit_token',
        // A — Identity
        'family_name', 'first_name', 'other_names', 'gender', 'dob',
        'country_of_birth', 'place_of_birth', 'country_of_citizenship',
        'other_citizenships', 'national_id', 'partnership_status',
        'birth_certificate_available', 'birth_certificate_reason', 'face_image_consent',
        // B — NZ immigration history
        'current_country', 'previous_nz_visa', 'current_address', 'email', 'phone',
        // C — Visa details
        'applying_as', 'visa_type', 'partner_living_together', 'partner_12_months',
        'partner_same_period', 'partner_close_relatives', 'child_dependent',
        // D — Character
        'character_convicted', 'character_removed', 'character_investigation',
        'character_visa_refused', 'lived_other_country_5y', 'previous_police_certificate',
        // E — Health
        'health_tb', 'health_renal', 'health_hospital', 'health_residential', 'health_pregnant',
        'countries_visited_3m', 'previous_xray', 'previous_medical_cert',
        // F — Work history
        'currently_working', 'current_employer_name', 'current_employer_address',
        'current_employer_phone', 'current_employer_email', 'current_occupation',
        'current_start', 'current_end', 'previous_work',
        // G — Other contacts
        'nz_contacts',
        // H — Declaration
        'declaration_accepted', 'signature_name', 'signature_date', 'terms_accepted',
        'documents', 'document_files',
    ];

    protected $casts = [
        'dob' => 'date',
        'signature_date' => 'date',
        'previous_work' => 'array',
        'documents' => 'array',
        'document_files' => 'array',
        'declaration_accepted' => 'bool',
        'terms_accepted' => 'bool',
        'face_image_consent' => 'bool',
    ];

    public function assessment(): MorphOne
    {
        return $this->morphOne(Assessment::class, 'intakeable');
    }
}
