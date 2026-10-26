<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class WorkIntake extends Model
{
    protected $fillable = [
        'intake_id', 'status', 'edit_token',
        'family_name', 'first_name', 'other_names', 'gender', 'dob',
        'country_of_birth', 'place_of_birth', 'current_address', 'email', 'phone',
        'country_of_citizenship', 'other_citizenships', 'national_id', 'partnership_status',
        'current_country', 'previous_nz_visa', 'previous_nz_visa_details', 'previous_nzeta',
        'australian_pr', 'travelled_nz', 'last_nz_departure', 'over_24_months',
        'employer_name', 'employer_is_family', 'employer_family_relation', 'self_employed',
        'job_start_date', 'hourly_rate', 'supports_dependent_children', 'job_skills_level',
        'character_convicted', 'character_investigation', 'character_deported',
        'character_visa_refused', 'lived_other_country_5y', 'lived_other_country_details',
        'health_tb', 'health_renal', 'health_hospital', 'health_residential', 'health_pregnant',
        'currently_working', 'current_job_title', 'current_job_duties', 'current_job_start',
        'current_job_country', 'current_job_region', 'current_employer_name',
        'current_employer_address', 'current_employer_phone', 'current_employer_email',
        'previous_roles', 'family_members', 'has_nz_contacts', 'nz_contacts',
        'military_compulsory', 'military_undertaken', 'military_details',
        'travelled_internationally', 'travel_trips', 'declaration_accepted',
        'signature_name', 'signature_date',
        // Extra processing questions (birth cert / face image / medical / police).
        'birth_certificate_available', 'birth_certificate_reason', 'face_image_consent',
        'medical_cert_recent', 'medical_cert_submitted_last', 'medical_cert_type',
        'police_certificate_available',
        // Work Interest & Qualifications document tab (see the intake controller).
        'documents', 'document_files',
    ];

    protected $casts = [
        'dob' => 'date',
        'last_nz_departure' => 'date',
        'job_start_date' => 'date',
        'current_job_start' => 'date',
        'signature_date' => 'date',
        'hourly_rate' => 'decimal:2',
        'previous_roles' => 'array',
        'family_members' => 'array',
        'nz_contacts' => 'array',
        'travel_trips' => 'array',
        'documents' => 'array',
        'document_files' => 'array',
        'declaration_accepted' => 'bool',
        'face_image_consent' => 'bool',
    ];

    public function assessment(): MorphOne
    {
        return $this->morphOne(Assessment::class, 'intakeable');
    }
}
