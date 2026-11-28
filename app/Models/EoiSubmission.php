<?php

namespace App\Models;

use App\Support\OnboardingPipeline;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EoiSubmission extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'accommodation_eoi_submissions';

    protected $fillable = [
        'public_token',
        // Section 1
        'full_legal_name', 'id_number', 'visa_status', 'visa_status_other',
        'nationality', 'nationality_other', 'preferred_name', 'email', 'mobile', 'age',
        // Section 2
        'room_type_interest', 'property_interested', 'tenancy_start_date', 'stay_duration',
        // Section 3
        'occupants', 'occupant_ages', 'has_children', 'children_ages', 'has_pets', 'pet_details',
        // Section 4
        'rent_funding', 'rent_funding_other', 'employment_status', 'employment_status_other',
        // Section 5
        'current_address', 'has_rented_before', 'current_address_duration', 'living_situation', 'reason_for_moving',
        // Section 6
        'smokes_or_vapes', 'drinks_alcohol', 'work_hours', 'flatmate_description',
        // Section 7
        'viewing_available_7days', 'preferred_viewing_time',
        // Section 8
        'confirm_accurate', 'consent_collection',
        // Workflow
        'status', 'form_type',
        // Onboarding pipeline (additive)
        'property_id', 'lead_temperature', 'viewing_email_sent_at',
        'viewing_scheduled_at', 'viewing_completed_at', 'viewing_outcome', 'post_viewing_followup_at',
        'pre_tenancy_form_sent_at', 'pre_tenancy_form_completed_at', 'pre_tenancy_form_data',
        'tenancy_agreement_sent_at', 'tenancy_agreement_signed_at',
        'invoice_amount_nzd', 'invoice_sent_at', 'payment_confirmed_at',
        'move_in_date', 'not_proceeding_reason', 'declined_reason',
        'converted_to_tenant_id', 'assigned_to_user_id', 'internal_notes',
        'registration_reviewed_at', 'verified_move_in_amount_nzd', 'move_in_invoice_id', 'viewing_attendee_id', 'welcome_email_sent_at',
    ];

    protected $casts = [
        'age' => 'integer',
        'tenancy_start_date' => 'date',
        'has_children' => 'boolean',
        'has_pets' => 'boolean',
        'has_rented_before' => 'boolean',
        'smokes_or_vapes' => 'boolean',
        'viewing_available_7days' => 'boolean',
        'confirm_accurate' => 'boolean',
        'consent_collection' => 'boolean',
        // Onboarding
        'viewing_email_sent_at' => 'datetime',
        'viewing_scheduled_at' => 'datetime',
        'viewing_completed_at' => 'datetime',
        'post_viewing_followup_at' => 'datetime',
        'pre_tenancy_form_sent_at' => 'datetime',
        'pre_tenancy_form_completed_at' => 'datetime',
        'tenancy_agreement_sent_at' => 'datetime',
        'tenancy_agreement_signed_at' => 'datetime',
        'invoice_sent_at' => 'datetime',
        'payment_confirmed_at' => 'datetime',
        'move_in_date' => 'date',
        'pre_tenancy_form_data' => 'array',
        'invoice_amount_nzd' => 'decimal:2',
        'registration_reviewed_at' => 'datetime',
        'verified_move_in_amount_nzd' => 'decimal:2',
        'welcome_email_sent_at' => 'datetime',
    ];

    protected $appends = ['days_at_current_stage'];

    protected static function booted(): void
    {
        // Denormalise lead_temperature from form_type on create, so new public
        // submissions get it without touching the public eoiStore endpoints.
        static::creating(function (EoiSubmission $submission) {
            if (empty($submission->lead_temperature) && ! empty($submission->form_type)) {
                $submission->lead_temperature = $submission->form_type;
            }
        });
    }

    /** Label used in the activity log feed. */
    /**
     * Ensure the record has a public token (bearer credential for the emailed
     * Pre-Tenancy form link) and return it. Generated lazily the first time the
     * link is needed, then reused.
     */
    public function ensurePublicToken(): string
    {
        if (! $this->public_token) {
            $this->public_token = \Illuminate\Support\Str::random(48);
            $this->saveQuietly();
        }

        return $this->public_token;
    }

    /** Absolute URL to the tenant's native Pre-Tenancy form. */
    public function preTenancyFormUrl(): string
    {
        return rtrim(config('app.url'), '/').'/pre-tenancy/'.$this->ensurePublicToken();
    }

    /** Has the tenant submitted the Pre-Tenancy form? */
    public function preTenancyFormCompleted(): bool
    {
        return (bool) $this->pre_tenancy_form_completed_at;
    }

    public function activityLabel(): string
    {
        return $this->full_legal_name ?? 'EOI submission';
    }

    // ---- Relationships ----------------------------------------------------

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function convertedTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'converted_to_tenant_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    // ---- Accessors --------------------------------------------------------

    /** Days since the record last changed — used to flag stalling applicants. */
    public function getDaysAtCurrentStageAttribute(): int
    {
        return $this->updated_at ? (int) $this->updated_at->diffInDays(now()) : 0;
    }

    // ---- Scopes -----------------------------------------------------------

    public function scopeNew($query)
    {
        return $query->where('status', 'new');
    }

    public function scopeReviewed($query)
    {
        return $query->where('status', 'reviewed');
    }

    public function scopeShortlisted($query)
    {
        return $query->where('status', 'shortlisted');
    }

    public function scopeAtStage($query, string $stage)
    {
        return $query->where('status', $stage);
    }

    public function scopeHot($query)
    {
        return $query->where('lead_temperature', 'hot');
    }

    public function scopeCold($query)
    {
        return $query->where('lead_temperature', 'cold');
    }

    /** Applicants still moving through the pipeline (excludes terminal states). */
    public function scopeInActivePipeline($query)
    {
        return $query->whereNotIn('status', OnboardingPipeline::OFF_PIPELINE);
    }
}
