<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class StudentIntake extends Model
{
    protected $fillable = [
        'intake_id', 'status', 'edit_token',
        'family_name', 'first_name', 'other_names', 'gender', 'dob',
        'country_of_birth', 'place_of_birth', 'current_address', 'overseas_address',
        'email', 'phone', 'country_of_citizenship', 'other_citizenships', 'national_id',
        'passport_number', 'passport_expiry', 'partnership_status', 'current_country',
        'previous_nz_visas', 'previous_nzeta', 'australian_pr', 'travelled_nz',
        'last_nz_departure', 'over_24_months',
        'character_convicted', 'character_investigation', 'character_deported',
        'character_visa_refused', 'lived_other_country_5y', 'lived_other_country_details',
        'health_tb', 'health_renal', 'health_hospital', 'health_residential', 'health_pregnant',
        'qualifications', 'currently_working', 'current_job_title', 'current_job_duties',
        'current_job_start', 'current_job_finish', 'current_job_country', 'current_job_region',
        'current_employer_name', 'current_employer_address', 'current_employer_phone',
        'current_employer_email', 'job_skills_level', 'family_members', 'has_nz_contacts', 'nz_contacts',
        'military_compulsory', 'military_undertaken', 'military_details',
        'travelled_internationally', 'travel_trips', 'programmes',
        'study_period_from', 'study_period_to', 'school_name', 'has_offer',
        'has_enough_funds', 'tuition_fee_nzd', 'available_funds', 'living_expenses_nzd',
        'has_sponsor', 'sponsor_relationship', 'sponsor_income_source',
        'can_provide_statements', 'has_other_assets', 'other_assets_details',
        'declaration_accepted', 'signature_name', 'signature_date',
        'birth_certificate_available', 'birth_certificate_reason', 'face_image_consent',
        'medical_cert_recent', 'medical_cert_submitted_last', 'medical_cert_type',
        'police_certificate_available',
        'documents', 'document_files',
    ];

    protected $casts = [
        'documents' => 'array',
        'document_files' => 'array',
        'dob' => 'date',
        'passport_expiry' => 'date',
        'last_nz_departure' => 'date',
        'current_job_start' => 'date',
        'current_job_finish' => 'date',
        'study_period_from' => 'date',
        'study_period_to' => 'date',
        'signature_date' => 'date',
        'tuition_fee_nzd' => 'decimal:2',
        'living_expenses_nzd' => 'decimal:2',
        'previous_nz_visas' => 'array',
        'previous_nzeta' => 'array',
        'australian_pr' => 'array',
        'qualifications' => 'array',
        'family_members' => 'array',
        'nz_contacts' => 'array',
        'travel_trips' => 'array',
        'available_funds' => 'array',
        'declaration_accepted' => 'bool',
        'face_image_consent' => 'bool',
    ];

    public function assessment(): MorphOne
    {
        return $this->morphOne(Assessment::class, 'intakeable');
    }
}
