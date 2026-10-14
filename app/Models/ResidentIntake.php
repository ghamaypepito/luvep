<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentIntake extends Model
{
    use LogsActivity;

    protected $fillable = [
        'intake_id',
        'payment_status',
        'payment_session_id',
        'payment_amount_cents',
        'payment_currency',
        'paid_at',
        'booking_id',
        'first_name',
        'last_name',
        'dob',
        'nationality',
        'birth_certificate_available',
        'birth_certificate_reason',
        'email',
        'phone',
        'passport_number',
        'passport_expiry',
        'issuing_country',
        'face_image_consent',
        'current_visa_type',
        'current_visa_other',
        'current_visa_expiry',
        'nz_arrival_date',
        'previous_nz_visa_history',
        'job_title',
        'employer_name',
        'employment_start',
        'employment_type',
        'hourly_rate',
        'highest_qualification',
        'institution_name',
        'country_of_study',
        'nzqa_status',
        'nzqa_iqa_reference',
        'nz_skilled_years',
        'total_skilled_years',
        'career_summary',
        'english_evidence',
        'english_test_score',
        'english_test_date',
        'include_family',
        'family_members',
        'documents',
        'document_files',
        'character_health_disclosure',
        'medical_cert_recent',
        'medical_cert_submitted_last',
        'medical_cert_type',
        'police_certificate_available',
        'other_notes',
        'status',
        'edit_token',
    ];

    protected $casts = [
        'dob' => 'date',
        'passport_expiry' => 'date',
        'current_visa_expiry' => 'date',
        'nz_arrival_date' => 'date',
        'employment_start' => 'date',
        'english_test_date' => 'date',
        'documents' => 'array',
        'document_files' => 'array',
        'family_members' => 'array',
        'face_image_consent' => 'boolean',
        'hourly_rate' => 'decimal:2',
        'nz_skilled_years' => 'decimal:1',
        'total_skilled_years' => 'decimal:1',
        'paid_at' => 'datetime',
        'payment_amount_cents' => 'int',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }
}
