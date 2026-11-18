<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class VisitorIntake extends Model
{
    protected $fillable = [
        'intake_id', 'status', 'edit_token',
        'family_name', 'first_name', 'other_names', 'gender', 'dob',
        'country_of_birth', 'place_of_birth', 'country_of_citizenship',
        'passport_number', 'passport_expiry', 'other_citizenships', 'national_id',
        'partnership_status', 'current_address', 'town_city', 'region', 'postcode',
        'phone', 'email', 'current_country', 'previous_nz_visa', 'previous_nzeta',
        'australian_pr', 'travelled_nz', 'last_nz_departure', 'over_24_months',
        'character_convicted', 'character_deported', 'character_investigation',
        'character_visa_refused', 'lived_other_country_5y', 'previous_police_certificate',
        'health_tb', 'health_renal', 'health_hospital', 'health_residential', 'health_pregnant',
        'previous_xray', 'previous_inz1007', 'inz_requested_medical',
        'has_tertiary', 'qualification_duration', 'qualification_name',
        'qualification_completed', 'education_provider',
        'currently_working', 'current_job_title', 'current_job_duties', 'current_job_start',
        'current_job_finish', 'current_job_country', 'current_job_region',
        'current_employer_name', 'current_employer_address', 'current_employer_phone',
        'current_employer_email', 'job_skills_level', 'family_members', 'has_nz_contacts', 'nz_contacts',
        'military_compulsory', 'military_undertaken', 'travelled_internationally', 'travel_trips',
        'purpose_of_visit', 'intended_stay_length', 'intended_from', 'intended_to',
        'multi_entry_plans', 'has_leave_permit', 'travel_funds_description',
        'can_provide_statements', 'has_other_assets', 'other_assets_details',
        'declaration_accepted', 'signature_name', 'signature_date',
        'birth_certificate_available', 'birth_certificate_reason', 'face_image_consent',
        'documents', 'document_files',
    ];

    protected $casts = [
        'documents' => 'array',
        'document_files' => 'array',
        'dob' => 'date',
        'passport_expiry' => 'date',
        'last_nz_departure' => 'date',
        'current_job_start' => 'date',
        'intended_from' => 'date',
        'intended_to' => 'date',
        'signature_date' => 'date',
        'family_members' => 'array',
        'nz_contacts' => 'array',
        'travel_trips' => 'array',
        'declaration_accepted' => 'bool',
        'face_image_consent' => 'bool',
    ];

    public function assessment(): MorphOne
    {
        return $this->morphOne(Assessment::class, 'intakeable');
    }
}
