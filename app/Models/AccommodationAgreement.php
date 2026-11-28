<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A generated Exalt flat/house-sharing agreement. Built from a pre-tenancy
 * submission (general PreTenancyForm or onboarding EoiSubmission), rendered
 * from a Blade template and stored as a PDF on the private disk.
 */
class AccommodationAgreement extends Model
{
    public const TYPES = [
        'whole_property' => 'Whole-Property House',
        'transient_flatmate' => 'Transient Flatmate — Short-Term',
        'standard_flatmate' => 'Standard Flatmate — Long-Term',
    ];

    public const LAYOUT_TERMS_FIRST = 'terms_first';

    public const LAYOUT_EXALT_2026_11 = 'exalt_2026_11';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';       // emailed a signing link

    public const STATUS_VIEWED = 'viewed';   // client opened the signing page

    public const STATUS_SIGNED = 'signed';

    protected $fillable = [
        'agreement_type', 'pre_tenancy_form_id', 'eoi_submission_id', 'property_id', 'tenant_id',
        'client_name', 'property_address', 'data', 'status',
        'pdf_path', 'signing_token', 'signed_pdf_path', 'signer_name',
        'signature_data', 'signer_ip', 'signer_user_agent',
        'sent_at', 'viewed_at', 'signed_at', 'created_by',
    ];

    protected $casts = [
        'data' => 'array',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'signed_at' => 'datetime',
    ];

    /** Public signing URL (bearer token), once a link has been generated. */
    public function signingUrl(): ?string
    {
        return $this->signing_token ? url("/agreement/{$this->signing_token}/sign") : null;
    }

    public function preTenancyForm()
    {
        return $this->belongsTo(PreTenancyForm::class);
    }

    public function eoiSubmission()
    {
        return $this->belongsTo(EoiSubmission::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->agreement_type] ?? $this->agreement_type;
    }
}
