<?php

namespace App\Models;

use App\Observers\LeadObserver;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([LeadObserver::class])]
class Lead extends Model
{
    use LogsActivity, SoftDeletes;

    /**
     * Education-team-specific lifecycle stages. Distinct from the global
     * pipeline below — these track the downstream Endorsement → Offer →
     * Visa → Enrolment flow that the Education portal owns once a lead is
     * converted to a student. Surfaced as the dropdown in the Students
     * dashboard's Status column.
     */
    // The full education pipeline — the 15-stage management-report workflow.
    // A student's position is tracked by `education_stage`. Stages 1–6 are the
    // pre-enrolment lead journey, 7–13 the enrolment→course path, and 14–15 the
    // out-of-process visa outcomes.
    public const EDUCATION_STAGES = [
        'New Lead',
        'Pre-Screening Done',
        'For Proposal',
        'Proposal Sent',
        'Engagement Sent',
        'Goal Setting Done',
        'School Enrolment',
        'Conditional Offer',
        'Unconditional Offer',
        'Endorsed to Immigration',
        'Visa Lodged',
        'Approved Visa',
        'Started Course',
        // Education-only end-stages — let the Education team keep tracking a
        // student after the visa outcome, without touching the Immigration
        // status. NOT part of EDUCATION_STAGES_IMMIGRATION (no handoff).
        'Course Ongoing',
        'Completed',
        'For Relodgement',
        'Declined Visa',
    ];

    /** Default stage a newly-converted education student starts on. */
    public const EDUCATION_STAGE_DEFAULT = 'School Enrolment';

    /**
     * Subset of EDUCATION_STAGES that hand the lead off to the Immigration
     * team. Anyone at one of these stages is treated as an immigration
     * case for routing purposes — they appear on the Immigration
     * dashboard, the Cases list, and the Immigration tab of the Education
     * Students page, even if `is_immigration_case` hasn't been flipped
     * manually. Single source of truth — referenced by the model scope
     * below and by the Students.jsx tab routing.
     */
    public const EDUCATION_STAGES_IMMIGRATION = [
        'Endorsed to Immigration',
        'Visa Lodged',
        'Approved Visa',
        'For Relodgement',
        'Declined Visa',
    ];

    /**
     * English-team lifecycle. Tracks the PTE / DIY review and test-prep
     * flow. Setting `english_stage` to any of these routes the lead to
     * the English tab on the Education Students page.
     */
    public const ENGLISH_STAGES = [
        'PTE Review',
        'DIY Review',
        'For PTE Mocktest',
        'For PTE Exam',
    ];

    /**
     * Immigration-team lifecycle. Tracks the case from initial endorsement
     * through INZ outcome. Setting `immigration_stage` to any of these
     * routes the lead to the Immigration tab.
     */
    public const IMMIGRATION_STAGES = [
        'For Assessment',
        'Endorsed',
        'Agreement Sent',
        'Agreement Signed',
        'For Agreement & Invoice',
        'Invoice Paid',
        // The application is prepared and cleared to file — the applicant has
        // been asked to confirm before it goes to INZ. Sits just before
        // lodgement. Staff may attach an optional note when moving here.
        'Request to Lodge',
        'Visa Lodged',
        // Onshore applicants are granted an interim visa that keeps them
        // lawful while INZ decides. It sits after lodgement and before any
        // RFI/outcome — order matters, the list is used as a forward-only
        // rank by CaseStepService::deriveStage().
        'Interim Visa Issued',
        'Request for Medical',
        'Request for Information',
        // The adviser has responded to INZ's RFI — case is back with INZ
        // awaiting a decision.
        'RFI Responded',
        'Approved in Principle',
        'Approved Visa',
        'Decline Visa',
        // Terminal — the applicant withdrew (or the case was withdrawn). Staff
        // may attach an optional note explaining why when moving here.
        'Withdrawn',
    ];

    /**
     * Case priority levels, surfaced on the Cases board as a coloured
     * avatar — urgent (red, today), high (orange, this week), medium
     * (yellow, on track), low (green, no rush), done (completed).
     */
    public const IMMIGRATION_PRIORITIES = ['urgent', 'high', 'medium', 'low', 'done'];

    /**
     * Named people who can be assigned to a lead while it sits at a given
     * sub-stage. Free-text labels (not user FKs) — "DIY" is a handling mode,
     * not a person — surfaced as a dropdown beside the stage in the Students
     * dashboard + New Student modal. Stored on `english_assignee` /
     * `immigration_assignee`.
     */
    public const ENGLISH_STAGE_ASSIGNEES = ['Paula', 'Frank', 'DIY'];

    public const IMMIGRATION_STAGE_ASSIGNEES = ['Hendry', 'Tarun', 'Dev'];

    /**
     * Query scope — leads currently in the Immigration team's queue.
     * The two qualifying signals are:
     *   1. `is_immigration_case = true` — explicit "Convert to Immigration
     *      Case" action on the lead detail page.
     *   2. `education_stage` is one of the EDUCATION_STAGES_IMMIGRATION
     *      values — the Education team has handed the file across.
     *
     * `immigration_stage` is intentionally NOT a qualifying signal on its
     * own. Staff can set it from the Students dashboard purely as a
     * handoff-tracking note on a still-active student — treating that as
     * "now in Cases" pulled pure students (e.g. anyone Study=ACTIVE who
     * had their immigration_stage stamped for reference) into the
     * Immigration Cases list by mistake. The flag/handoff-stage above are
     * what determine queue membership.
     */
    public function scopeImmigrationCase($query)
    {
        return $query->where(function ($q) {
            $q->where('is_immigration_case', true)
                ->orWhereIn('education_stage', self::EDUCATION_STAGES_IMMIGRATION);
        });
    }

    /**
     * Query scope — leads that are still in the Sales pipeline. Any
     * lead that's been moved to another department (student / English
     * student / immigration case / accommodation client) drops out of
     * the leads-table view. Used by the admin + sales leads listings.
     */
    public function scopeInLeadPipeline($query)
    {
        return $query
            ->where('is_student', false)
            ->where('is_immigration_case', false)
            ->where('is_accommodation_client', false)
            ->where('is_english_student', false);
    }

    /**
     * The Students module universe — the exact set the Education "Students"
     * page lists across its Education / English / Immigration tabs. It is:
     *   - every converted student (is_student), plus
     *   - every English learner (english_stage set, or stage = "English Pro").
     *
     * A student who is handed to immigration keeps is_student = true (see
     * EducationController::updateStudentField), so they stay in this register
     * and their immigration stage is tracked under the Immigration tab — that
     * is the "student who is also a case" the module is designed around.
     *
     * Pure immigration cases that were NEVER education students are deliberately
     * excluded — they live on the Immigration Cases page, not here. Kept as a
     * scope so the Students list and the education report count the same rows.
     */
    public function scopeInStudentsRegister($query)
    {
        return $query->where(function ($q) {
            $q->where('is_student', true)
                ->orWhere('stage', 'English Pro')
                ->orWhereNotNull('english_stage');
        });
    }

    /**
     * Append a dated entry to the lead's status timeline. Callers mutate the
     * attribute here and persist with their own save(), so a single stage
     * update writes one history row. `at` is captured per change so the
     * Pipeline can show exactly when each status was reached, and `by_name`
     * snapshots the acting staff member.
     */
    /**
     * Study-proposal verification status: 'pending' | 'verified' | 'approved',
     * or null when there's no proposal under review (legacy proposals — those
     * with programs but no review record — are treated as already live).
     */
    public function proposalStatus(): ?string
    {
        $r = is_array($this->proposal_review) ? $this->proposal_review : [];

        return $r['status'] ?? null;
    }

    /** A proposal is visible to the client only once approved (or legacy-live). */
    public function proposalIsLive(): bool
    {
        $status = $this->proposalStatus();

        return $status === 'approved' || $status === null;
    }

    /** Consultancy-agreement verification status: pending | verified | approved | null. */
    public function consultancyStatus(): ?string
    {
        $r = is_array($this->consultancy_review) ? $this->consultancy_review : [];

        return $r['status'] ?? null;
    }

    /** A consultancy agreement is sent to the client only once approved (legacy = live). */
    public function consultancyIsLive(): bool
    {
        $status = $this->consultancyStatus();

        return $status === 'approved' || $status === null;
    }

    public function pushStageHistory(string $department, ?string $stage, ?string $assignee = null): void
    {
        $history = $this->stage_history ?? [];
        $user = auth()->user();

        $history[] = [
            'department' => $department,
            'stage' => $stage,
            'assignee' => $assignee,
            'at' => now()->toIso8601String(),
            'by_id' => $user?->id,
            'by_name' => $user?->name,
        ];

        $this->stage_history = $history;
    }

    /**
     * The RFI response deadline lives on the most recent "Request for
     * Information" stage-history entry (set by ImmigrationController::
     * requestForInformation) rather than a dedicated column — the `leads` god
     * table is already at InnoDB's row-size limit. Read-only; write it through
     * that action, which annotates the stage entry.
     */
    public function getMedicalDeadlineAttribute(): ?\Illuminate\Support\Carbon
    {
        foreach (array_reverse($this->stage_history ?? []) as $entry) {
            if (($entry['stage'] ?? null) === 'Request for Medical' && ! empty($entry['medical_deadline'])) {
                return \Illuminate\Support\Carbon::parse($entry['medical_deadline']);
            }
        }

        return null;
    }

    public function getRfiDeadlineAttribute(): ?\Illuminate\Support\Carbon
    {
        foreach (array_reverse($this->stage_history ?? []) as $entry) {
            if (($entry['stage'] ?? null) === 'Request for Information' && ! empty($entry['rfi_deadline'])) {
                return \Illuminate\Support\Carbon::parse($entry['rfi_deadline']);
            }
        }

        return null;
    }

    /**
     * Move the immigration case forward to `$to`, but only from one of the
     * `$from` stages (an unset stage always qualifies) — so an automatic
     * transition never downgrades a case that's already further along or clobbers
     * a signed/lodged one. Stamps the stage-tracking columns + history and saves.
     * Returns true when the stage actually moved.
     */
    public function advanceImmigrationStage(string $to, array $from, ?int $byUserId = null, bool $notify = true): bool
    {
        $current = $this->immigration_stage;
        if ($current === $to) {
            return false;
        }
        if ($current !== null && ! in_array($current, $from, true)) {
            return false;
        }

        $this->immigration_stage = $to;
        $this->stage_updated_at = now();
        $this->stage_updated_by = $byUserId;
        $this->pushStageHistory('immigration', $to, $this->immigration_assignee);
        $notify ? $this->save() : $this->saveQuietly();

        // Email automation — fire the per-stage event so any configured
        // messages go out (no-op unless an admin enabled one).
        if ($notify) app(\App\Services\EmailAutomationService::class)->fire(
            'immigration.stage.'.\Illuminate\Support\Str::slug($to, '_'),
            $this,
            ['stage' => $to],
        );

        return true;
    }

    /**
     * Canonical lead-pipeline stages. Single source of truth — referenced
     * by SalesController validation and the LeadController stage-update
     * endpoint. Order is the canonical pipeline order surfaced in the UI.
     */
    public const STAGES = [
        'New Leads',
        'Contact Attempted',
        'Contacted for Booking',
        'Booking Confirmation',
        'Missed the Meeting',
        'Qualified but Not Ready',
        'Qualified but No Funds',
        'Consultation Done',
        'Proposal Sent',
        'Program Selected',
        'Consultancy Agreement Sent',
        'Consultancy Agreement Signed',
        'Consultancy Agreement',
        'English Pro',
        'School Enrollment',
        'Visa Process',
        'Not Qualified',
        'Work Pathway / Other',
    ];

    protected $fillable = [
        'lead_id', 'tracking_code', 'first_name', 'middle_name', 'last_name', 'suffix', 'dob', 'other_names', 'email', 'phone', 'referral', 'school_id',
        'gender', 'marital_status', 'branch', 'stage', 'status', 'priority',
        'country_of_birth', 'place_of_birth', 'citizenship',
        'residence_city', 'residence_state', 'residence_country',
        'has_passport', 'passport_number', 'passport_expiry', 'passport_path',
        'terms_accepted', 'work_info', 'financial_info', 'gap_explanation',
        'education_notes', 'event_id', 'event_session_id', 'event_response',
        'event_notes', 'event_notes_updated_at', 'event_notes_updated_by',
        'immigration_info', 'character_info', 'health_info', 'family_info',
        'nz_contacts_info', 'military_info', 'source_of_funds_info',
        'home_ties_info', 'declaration_accepted',
        'ai_analysis', 'ai_analysis_status',
        'source',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
        // Lead Portal invitation lifecycle
        'portal_invitation_status',
        'portal_invitation_requested_by', 'portal_invitation_requested_at',
        'portal_invitation_approved_by',  'portal_invitation_approved_at',
        'portal_invitation_token', 'portal_invitation_expires_at',
        'portal_invitation_accepted_at',
        // Journey panel — pre-screening + goal-setting captures
        'date_of_first_contact', 'date_of_engagement',
        'prescreened_by', 'prescreened_notes',
        'goal_setting_status', 'goal_setting_by', 'goal_setting_notes',
        // Sales-dashboard mirror columns
        'calendar_date', 'client_info_link', 'call_update_form_link',
        'document_checklist',
        'hidden_track_documents',
        // Per-lead ad-hoc document rows [{key, name}] added on the Documents
        // tab — scoped to this one lead, uploaded against by a custom.* key.
        'custom_documents',
        // Up to 3 program IDs staff have proposed for this lead — drives
        // the "Proposal" tab on the Proposal & Agreements page and the
        // program shortlist rendered on the tracker.
        'proposed_program_ids',
        // Per-program "why this program" reasons, keyed by program id.
        'proposed_program_reasons',
        // Per-program verification overrides (fee/school/intake/status/edited),
        // keyed by program id — drives the Program Verification table.
        'proposed_program_meta',
        'consultancy_review',
        // Study-proposal verification workflow (pending → verified → approved).
        'proposal_review',
        // Lead's chosen program (FK to programs.id) from that shortlist,
        // set by the tracker's "Choose this one" action.
        'preferred_program_id', 'preferred_program_chosen_at',
        'section_verifications',
        // Student conversion flag
        'is_student', 'student_converted_at', 'student_converted_by',
        // Multi-service flags
        'is_immigration_case', 'immigration_converted_at', 'immigration_converted_by', 'assessment_id',
        'is_accommodation_client', 'accommodation_converted_at', 'accommodation_converted_by',
        'is_english_student', 'english_converted_at', 'english_converted_by',
        // Staff member responsible for this lead (drives assignment notifications)
        'assigned_to',
        // Recruiting Agent (role='agent') who added this lead. Scopes the
        // Agent portal to its own leads + surfaces on the Sales Agents tab.
        'agent_id',
        // Last time the lead opened their /track/{code} page
        'last_seen_at',
        // INZ lodgement tracking
        'inz_visa_type', 'inz_lodged_at', 'inz_reference', 'inz_status', 'inz_decision_at',
        'inz_client_number', 'inz_application_number', 'inz_medical_ref', 'nzer_number',
        // Planned INZ filing date + applicant IRD number.
        'target_lodgement_at', 'ird_number',
        // IAA / Privacy Act gating
        'services_agreement_signed_at',
        // Lead-portal acknowledgment of Consultancy + English Engagement
        'agreements_acknowledged_at',
        // Students Dashboard mirror (Education team's spreadsheet columns
        // that don't already live elsewhere on the lead row).
        'student_payment', 'student_school', 'student_coop', 'student_oop',
        'student_gdrive_link', 'student_comments', 'gdrive_folder_id',
        // Education-team-specific lifecycle stage (see EDUCATION_STAGES).
        'education_stage',
        // Last stage movement — used by the Students / Cases tables'
        // "Updated [date] · Endorsed by [Name]" subtitle. Only touched by
        // stage-update + creation flows so the timestamp is a reliable
        // signal of actual pipeline movement.
        'stage_updated_at', 'stage_updated_by',
        // Last *staff* touch of any kind — see the `updating` hook in
        // booted(). Drives the Updated column on the portal case tables.
        'last_activity_at', 'last_activity_by', 'last_activity_desc',
        // English / Immigration sub-stage tracks (see ENGLISH_STAGES,
        // IMMIGRATION_STAGES). Each carries an optional named assignee.
        'english_stage', 'english_assignee',
        'immigration_stage', 'immigration_priority', 'immigration_assignee',
        // Case custody (Build 12 phase 2) — one owner at a time, changed only
        // through an explicit handoff. See ImmigrationController::handoff().
        'current_owner_id', 'owner_since',
        // Full dated timeline of every department status change — drives the
        // Pipeline "when did this status happen" view. See recordStageChange().
        'stage_history',
        // INZ-style sub-status tracker for the Visa Lodge / Visa Outcome stages
        // (activities + per-activity status history). JSON so it never touches
        // the leads row-size limit. See App\Services\Immigration\VisaProgressService.
        'visa_progress',

        // ── Wide profile-info build — columns surfaced by the
        //    LeadController::updatePersonal allow-list and rendered
        //    across the 9 editable Personal Info section cards. Grouped
        //    by card for readability. ─────────────────────────────────
        // 1. Personal identity (most existed; new = preferred_name,
        //    whatsapp, address lines + postcode, NZ continuous residence)
        'preferred_name', 'whatsapp',
        'residence_address_line_1', 'residence_address_line_2', 'residence_address_postcode',
        'has_been_in_nz_continuously', 'nz_continuous_residence_months',
        // 2. Passport (existing has_passport / passport_number /
        //    passport_expiry / passport_path stay)
        'passport_issuing_country', 'passport_issue_date',
        // 3. Current NZ visa (all new)
        'current_nz_visa_type', 'current_nz_visa_number',
        'current_nz_visa_issued_date', 'current_nz_visa_expiry_date',
        'previous_nz_visa_type',
        // 4. Study plans (flat columns alongside the related lead_study_plans
        //    table — legacy rows continue to flow through that relation)
        'preferred_course', 'preferred_qualification_level',
        'preferred_city_of_study', 'preferred_intake',
        'english_test_type', 'english_test_overall_score',
        'english_test_listening', 'english_test_reading',
        'english_test_writing', 'english_test_speaking',
        'english_test_date', 'target_institution',
        // 5. Financial
        'funding_source', 'estimated_total_cost_nzd', 'available_funds_nzd',
        'supports_partner_or_dependents', 'has_property_in_home_country',
        'annual_income_nzd', 'annual_income_currency', 'bank_funds_evidence_provided',
        // 6. Employment
        'employment_type', 'current_employer_name', 'current_position_title',
        'current_employer_country', 'current_employer_phone', 'current_employer_email',
        'current_employment_start_date',
        'current_salary_nzd', 'years_of_relevant_experience',
        'has_anzsco_listed_role', 'anzsco_code',
        'has_nz_professional_registration', 'nz_professional_registration_body',
        // 7. Education background
        'highest_qualification', 'highest_qualification_field',
        'highest_qualification_country', 'highest_qualification_year_completed',
        'has_nzqa_assessment', 'nzqa_assessment_level',
        // 8. Family
        'has_children', 'number_of_children', 'dependent_children_notes',
        'has_dependent_partner', 'partner_in_nz', 'intends_to_bring_family',
        // 9. Health & character
        'has_health_disclosure', 'health_disclosure_notes',
        'has_character_disclosure', 'character_disclosure_notes',
        'has_been_declined_visa', 'declined_visa_details',
        'has_criminal_record', 'criminal_record_details',
        'meets_184_day_rule_two_years',
    ];

    protected $casts = [
        'work_info' => 'array',
        'event_response' => 'array',
        'event_notes_updated_at' => 'datetime',
        'financial_info' => 'array',
        'education_notes' => 'array',
        'immigration_info' => 'array',
        'character_info' => 'array',
        'health_info' => 'array',
        'family_info' => 'array',
        'nz_contacts_info' => 'array',
        'military_info' => 'array',
        'source_of_funds_info' => 'array',
        'home_ties_info' => 'array',
        'ai_analysis' => 'array',
        'age' => 'integer',
        'portal_invitation_requested_at' => 'datetime',
        'portal_invitation_approved_at' => 'datetime',
        'portal_invitation_expires_at' => 'datetime',
        'portal_invitation_accepted_at' => 'datetime',
        'date_of_first_contact' => 'date',
        'date_of_engagement' => 'date',
        'calendar_date' => 'date',
        'document_checklist' => 'array',
        'hidden_track_documents' => 'array',
        'custom_documents' => 'array',
        'proposed_program_ids' => 'array',
        'proposed_program_reasons' => 'array',
        'proposed_program_meta' => 'array',
        'proposal_review' => 'array',
        'consultancy_review' => 'array',
        'preferred_program_chosen_at' => 'datetime',
        'section_verifications' => 'array',
        'agreements_acknowledged_at' => 'datetime',
        'stage_updated_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'owner_since' => 'datetime',
        'stage_history' => 'array',
        'visa_progress' => 'array',
        'last_seen_at' => 'datetime',
        'is_student' => 'boolean',
        'student_converted_at' => 'datetime',
        'is_immigration_case' => 'boolean',
        'immigration_converted_at' => 'datetime',
        'is_accommodation_client' => 'boolean',
        'accommodation_converted_at' => 'datetime',
        'inz_lodged_at' => 'datetime',
        'target_lodgement_at' => 'date',
        'inz_decision_at' => 'datetime',
        'services_agreement_signed_at' => 'datetime',

        // ── Wide profile-info build (column shapes match the
        //    widen_lead_personal_info_columns migration). ──────────────
        //    widen_lead_personal_info_columns migration). ──────────────
        'dob' => 'date',
        'passport_expiry' => 'date',
        'passport_issue_date' => 'date',
        'current_nz_visa_issued_date' => 'date',
        'current_nz_visa_expiry_date' => 'date',
        'current_employment_start_date' => 'date',
        'english_test_date' => 'date',
        // Encrypted at rest — Crypt::decryptString runs on read,
        // Crypt::encryptString on write. Existing plain-text passport
        // numbers were re-encrypted by the migration.
        // SafeEncrypted (not plain 'encrypted'): still encrypts on write, but on
        // read a value that can't be decrypted — e.g. encrypted under a previous
        // APP_KEY — degrades to null (logged) instead of throwing a
        // DecryptException ("The MAC is invalid") that would blank the whole
        // cases list when serialising every case's passport number.
        'passport_number' => \App\Casts\SafeEncrypted::class,
        'current_nz_visa_number' => \App\Casts\SafeEncrypted::class,
        // Booleans (mirrors migration column types).
        'has_been_in_nz_continuously' => 'boolean',
        'meets_184_day_rule_two_years' => 'boolean',
        'has_passport' => 'boolean',
        'supports_partner_or_dependents' => 'boolean',
        'has_property_in_home_country' => 'boolean',
        'bank_funds_evidence_provided' => 'boolean',
        'has_anzsco_listed_role' => 'boolean',
        'has_nz_professional_registration' => 'boolean',
        'has_nzqa_assessment' => 'boolean',
        'has_children' => 'boolean',
        'has_dependent_partner' => 'boolean',
        'partner_in_nz' => 'boolean',
        'intends_to_bring_family' => 'boolean',
        'has_health_disclosure' => 'boolean',
        'has_character_disclosure' => 'boolean',
        'has_been_declined_visa' => 'boolean',
        'has_criminal_record' => 'boolean',
        // Numeric.
        'nz_continuous_residence_months' => 'integer',
        'years_of_relevant_experience' => 'integer',
        'highest_qualification_year_completed' => 'integer',
        'number_of_children' => 'integer',
        'estimated_total_cost_nzd' => 'decimal:2',
        'available_funds_nzd' => 'decimal:2',
        'annual_income_nzd' => 'decimal:2',
        'current_salary_nzd' => 'decimal:2',
        'english_test_overall_score' => 'decimal:2',
        'english_test_listening' => 'decimal:2',
        'english_test_reading' => 'decimal:2',
        'english_test_writing' => 'decimal:2',
        'english_test_speaking' => 'decimal:2',
    ];

    /**
     * Computed accessors serialised onto every Lead JSON payload. `age`
     * is derived from `dob` (see getAgeAttribute below) so the Personal
     * Information section on the lead detail page can display it
     * without an extra client-side calculation.
     */
    protected $appends = ['age'];

    /** Portal account (User row with role='lead'), if one exists. */
    public function portalUser()
    {
        return $this->hasOne(User::class, 'lead_id');
    }

    /** Sub-agent contact profile (best time to call, channel, languages, …). */
    public function contactProfile()
    {
        return $this->hasOne(LeadContactProfile::class);
    }

    /**
     * A guaranteed-unique `LP-NNNNN` identifier.
     *
     * The old inline schemes (`LP-`.rand(...) and `LP-`.(max(id)+1000)) both
     * collide: `rand` can repeat, and basing the number on the primary-key max
     * breaks once the id and lead_id sequences drift apart (deletes, imports,
     * other creation paths) — the number then lands on a lead_id that already
     * exists and the insert dies on `leads_lead_id_unique`. Because a failed
     * insert never advances max(id), every retry recomputes the SAME colliding
     * value and fails forever (see the Sumit Kumar conversion loop).
     *
     * This derives the next number from the highest EXISTING `LP-` value (not
     * the pk), then steps forward past anything already taken — checking
     * soft-deleted rows too, since they still hold the unique lead_id.
     */
    public static function generateLeadId(): string
    {
        $maxLpNumber = (int) static::withTrashed()
            ->where('lead_id', 'like', 'LP-%')
            ->selectRaw('MAX(CAST(SUBSTRING(lead_id, 4) AS UNSIGNED)) as n')
            ->value('n');

        // Stay ahead of both the highest LP- number and the historical
        // max(id)+1000 baseline, so numbers never move backwards.
        $next = max($maxLpNumber + 1, (int) static::withTrashed()->max('id') + 1001);

        do {
            $candidate = 'LP-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            $next++;
        } while (static::withTrashed()->where('lead_id', $candidate)->exists());

        return $candidate;
    }

    /** Staff member who flipped is_student=true (or null on legacy rows). */
    public function studentConverter()
    {
        return $this->belongsTo(User::class, 'student_converted_by');
    }

    /** Staff member who flipped is_immigration_case=true. */
    public function immigrationConverter()
    {
        return $this->belongsTo(User::class, 'immigration_converted_by');
    }

    /** Staff member who last moved the lead's stage in any department. */
    public function stageUpdater()
    {
        return $this->belongsTo(User::class, 'stage_updated_by');
    }

    /** Staff member behind the most recent meaningful edit (or null). */
    public function lastActivityUser()
    {
        return $this->belongsTo(User::class, 'last_activity_by');
    }

    /** The case's current owner (custody), or null when unassigned. */
    public function owner()
    {
        return $this->belongsTo(User::class, 'current_owner_id');
    }

    /** Dependants (children / partner) included in this immigration case. */
    public function dependents()
    {
        return $this->hasMany(CaseDependent::class);
    }

    /**
     * When THIS case is itself a dependant tied to a parent's case, the linking
     * record (whose ->lead is the parent case). Null for a standalone case.
     */
    public function tiedAsDependent()
    {
        return $this->hasOne(CaseDependent::class, 'linked_lead_id');
    }

    /** Staff member this lead is currently assigned to (or null). */
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Recruiting Agent (role='agent') who added this lead, or null. */
    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    // ─── Computed accessors used by the Personal Info tab ─────────────

    /**
     * Age in whole years, derived from `dob`. Overrides the legacy `age`
     * column so the value can't drift out of sync with the date-of-birth
     * column the rest of the editing UI writes to. Returns null when DOB
     * is unset; falls back to whatever value sits in the legacy `age`
     * column so historical rows without DOB still show their imported age.
     */
    public function getAgeAttribute(): ?int
    {
        if (! empty($this->attributes['dob'])) {
            try {
                return \Illuminate\Support\Carbon::parse($this->attributes['dob'])->age;
            } catch (\Throwable $e) {
                // Bad date — fall through to legacy column.
            }
        }
        $legacy = $this->attributes['age'] ?? null;

        return $legacy !== null ? (int) $legacy : null;
    }

    /**
     * True for employment types that imply current paid work. Drives the
     * "current employer" sub-form revealed in the Employment section
     * card and the case-level filters on the Cases page.
     */
    public function getIsEmployedAttribute(): bool
    {
        return in_array($this->employment_type, ['Employed', 'Self-employed'], true);
    }

    /**
     * True when a current_nz_visa_type is populated — surfaces in the
     * /track page so the client sees their current visa status, and on
     * the Cases page summary chips.
     */
    public function getHasCurrentNzVisaAttribute(): bool
    {
        return ! empty($this->current_nz_visa_type);
    }

    /**
     * Whether the lead's passport_expiry sits within the supplied number
     * of days from today. Defaults to 180 days (Immigration NZ's six-
     * month rule). Property access `$lead->passport_expires_within_days`
     * uses the default; pass an explicit number to `getPassportExpiresWithinDaysAttribute`
     * for a different window.
     */
    public function getPassportExpiresWithinDaysAttribute(int $days = 180): bool
    {
        if (empty($this->attributes['passport_expiry'])) {
            return false;
        }
        try {
            $expiry = \Illuminate\Support\Carbon::parse($this->attributes['passport_expiry']);

            return $expiry->isFuture()
                && $expiry->diffInDays(\Illuminate\Support\Carbon::now()) <= $days;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Generate a short, customer-friendly tracking code that is guaranteed
     * unique against the leads table. Ambiguous characters (0/O, 1/I/L) are
     * stripped so codes can be read aloud or texted without confusion.
     *
     * Format: "EP-XXXXXXXX" (8 alphanumerics, ~30 bits of entropy — large
     * enough that a malicious enumerator can't realistically harvest live
     * codes against the rate-limited public lookup endpoint).
     */
    public static function generateTrackingCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $body = '';
            for ($i = 0; $i < 8; $i++) {
                $body .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = 'EP-'.$body;
        } while (static::where('tracking_code', $code)->exists());

        return $code;
    }

    /**
     * The bearer token for the standalone engagement-signing link. Generated
     * lazily the first time the engagement pack is emailed. Grants access to
     * ONLY this lead's engagement documents (see EngagementSigningController).
     */
    public function ensureEngagementSigningToken(): string
    {
        if (empty($this->engagement_signing_token)) {
            $this->forceFill(['engagement_signing_token' => \Illuminate\Support\Str::random(48)])->save();
        }

        return $this->engagement_signing_token;
    }

    /**
     * Boot hook: every new lead gets a tracking_code automatically. We
     * only generate one if the caller hasn't provided one (so seeders and
     * the backfill migration can pass their own).
     */
    protected static function booted(): void
    {
        static::creating(function (Lead $lead) {
            if (empty($lead->tracking_code)) {
                $lead->tracking_code = static::generateTrackingCode();
            }
        });

        // Stamp who last touched the record, and with what, before the row
        // is written — this is what the portals' Updated column reports.
        static::updating(fn (Lead $lead) => $lead->stampLastActivity());

        // Stage-triggered client emails — fire whenever the pipeline stage
        // transitions (from any of the several stage-change endpoints).
        // Guarded by wasChanged so each fires once per transition.
        //   • Contact Attempted → missed_the_call_1 now, missed_the_call_2 on
        //     day 3 (skipped then if the lead has since moved on).
        //   • Not Qualified     → not_qualified now.
        static::updated(function (Lead $lead) {
            if (! $lead->wasChanged('status') || empty($lead->email)) {
                return;
            }

            try {
                if ($lead->status === 'Contact Attempted') {
                    \App\Jobs\SendLeadFollowupEmail::sendKey('missed_the_call_1', $lead);
                    \App\Jobs\SendLeadFollowupEmail::dispatch($lead->id, 'missed_the_call_2', 'Contact Attempted')
                        ->delay(now()->addDays(3));
                } elseif ($lead->status === 'Contacted for Booking') {
                    \App\Jobs\SendLeadFollowupEmail::sendKey('contacted_for_booking_1', $lead);
                } elseif ($lead->status === 'Missed the Meeting') {
                    // missed_the_booking_1 now, missed_the_booking_2 on day 1
                    // (skipped then if the lead has moved off this stage).
                    \App\Jobs\SendLeadFollowupEmail::sendKey('missed_the_booking_1', $lead);
                    \App\Jobs\SendLeadFollowupEmail::dispatch($lead->id, 'missed_the_booking_2', 'Missed the Meeting')
                        ->delay(now()->addDays(1));
                } elseif ($lead->status === 'Not Qualified') {
                    \App\Jobs\SendLeadFollowupEmail::sendKey('not_qualified', $lead);
                } elseif ($lead->status === 'Qualified but No Funds') {
                    \App\Jobs\SendLeadFollowupEmail::sendKey('qualified_but_no_funds', $lead);
                } elseif ($lead->status === 'Qualified but Not Ready') {
                    \App\Jobs\SendLeadFollowupEmail::sendKey('qualified_but_not_ready', $lead);
                } elseif ($lead->status === 'Consultation Done') {
                    \App\Jobs\SendLeadFollowupEmail::sendKey('consultation_done', $lead);
                } elseif ($lead->status === 'Consultancy Agreement Signed') {
                    // Key intentionally spelled 'consultancy_signe' per template.
                    \App\Jobs\SendLeadFollowupEmail::sendKey('consultancy_signe', $lead);
                }
                // NB: the post-proposal feedback drip (proposal_send_1/2/3) is NOT
                // scheduled here — it's tied to the actual proposal email in
                // LeadDocumentController (SendLeadFollowupEmail::scheduleProposalDrip),
                // because the proposal is often emailed while the lead is already
                // in "Proposal Sent" (verification approval), so no status
                // transition fires.
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Stage-change follow-up failed', [
                    'lead_id' => $lead->id, 'status' => $lead->status, 'error' => $e->getMessage(),
                ]);
            }
        });

        // Immigration CASE stage-triggered client emails (config/stage_emails.php
        // immigration_map — e.g. "Invoice Paid" => invoice_paid). Fires once per
        // immigration_stage transition, catching every write path (the process
        // chain, the manual "Move to stage" dropdown, etc.) since all save here.
        static::updated(function (Lead $lead) {
            if (! $lead->wasChanged('immigration_stage') || empty($lead->email)) {
                return;
            }

            $key = config('stage_emails.immigration_map')[$lead->immigration_stage] ?? null;
            if (! $key) {
                return;
            }

            try {
                \App\Jobs\SendLeadFollowupEmail::sendKey($key, $lead);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Immigration stage email failed', [
                    'lead_id' => $lead->id, 'stage' => $lead->immigration_stage, 'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Changes that aren't staff "activity": system bookkeeping, background
     * analysis output, and the stamp fields themselves.
     */
    private const ACTIVITY_NOISE = [
        'created_at', 'updated_at', 'deleted_at',
        'ai_analysis', 'ai_analysis_status',
        'last_seen_at', 'stage_history',
        // Custody is stamped explicitly by the handoff (recordStaffActivity),
        // so the auto-stamp ignores these to avoid a generic "Updated
        // current_owner_id" description.
        'current_owner_id', 'owner_since',
        'last_activity_at', 'last_activity_by', 'last_activity_desc',
    ];

    /** Friendlier names for the columns staff change most often. */
    private const ACTIVITY_LABELS = [
        'immigration_stage' => 'Stage',
        'education_stage' => 'Stage',
        'english_stage' => 'Stage',
        'status' => 'Status',
        'immigration_priority' => 'Priority',
        'immigration_assignee' => 'Assignee',
        'assigned_to' => 'Assignee',
        'inz_visa_type' => 'Visa',
        'inz_status' => 'INZ status',
        'inz_reference' => 'INZ reference',
        'inz_lodged_at' => 'Lodged date',
        'inz_decision_at' => 'Decision date',
        'hidden_track_documents' => 'Tracker visibility',
        'section_verifications' => 'Section verification',
        'residence_country' => 'Country',
    ];

    /**
     * Record the current staff user as the author of this edit. Only real
     * staff count — queued jobs (no auth) and the client's own tracker
     * actions must not claim the Updated column.
     */
    protected function stampLastActivity(): void
    {
        $user = auth()->user();

        if (! $user || $user->isLead()) {
            return;
        }

        $changed = array_values(array_diff(array_keys($this->getDirty()), self::ACTIVITY_NOISE));

        if (empty($changed)) {
            return;
        }

        $this->last_activity_at = now();
        $this->last_activity_by = $user->id;
        $this->last_activity_desc = $this->describeActivity($changed);
    }

    /**
     * Stamp an action that isn't a column change on the lead itself — a
     * document review, a file deletion — so it still shows in the portals'
     * Updated column. Written quietly: no extra activity-log noise.
     */
    public function recordStaffActivity(string $description): void
    {
        $user = auth()->user();

        if (! $user || $user->isLead()) {
            return;
        }

        $this->forceFill([
            'last_activity_at' => now(),
            'last_activity_by' => $user->id,
            'last_activity_desc' => mb_substr($description, 0, 160),
        ])->saveQuietly();
    }

    /**
     * One-line summary of an edit, e.g. "Stage → Endorsed" or
     * "Updated Visa, Phone +2 more".
     *
     * @param  array<int, string>  $changed
     */
    protected function describeActivity(array $changed): string
    {
        foreach (['immigration_stage', 'education_stage', 'english_stage'] as $stageColumn) {
            if (in_array($stageColumn, $changed, true) && ! empty($this->{$stageColumn})) {
                return mb_substr('Stage → '.$this->{$stageColumn}, 0, 160);
            }
        }

        $shown = array_slice($changed, 0, 3);
        $labels = array_map(
            fn ($column) => self::ACTIVITY_LABELS[$column] ?? ucfirst(str_replace('_', ' ', $column)),
            $shown
        );

        $extra = count($changed) - count($shown);

        return mb_substr(
            'Updated '.implode(', ', $labels).($extra > 0 ? " +{$extra} more" : ''),
            0,
            160
        );
    }

    public function documentRequests()
    {
        return $this->hasMany(LeadDocumentRequest::class);
    }

    public function tags()
    {
        return $this->belongsToMany(LeadTag::class, 'lead_tag_lead')
            ->withTimestamps()
            ->withPivot('user_id');
    }

    public function notes()
    {
        return $this->hasMany(LeadNote::class);
    }

    public function tasks()
    {
        return $this->hasMany(LeadTask::class);
    }

    public function documents()
    {
        return $this->hasMany(LeadDocument::class);
    }

    /**
     * Version history of program proposals. The newest row is the active
     * shortlist (mirrors proposed_program_ids); older rows are kept so a new
     * proposal never discards the previous one.
     */
    public function proposals()
    {
        // Newest first. Order by id (not created_at) so versions saved in the
        // same second — e.g. a restore that backfills then snapshots — still
        // order deterministically by insertion.
        return $this->hasMany(LeadProposal::class)->orderByDesc('id');
    }

    /**
     * The applicant's latest uploaded Face image — used as the profile
     * picture (avatar) across the staff leads / cases / students tables and
     * the client tracker. Eager-load with `->with('faceImage')` to avoid an
     * N+1 in list views.
     */
    public function faceImage()
    {
        return $this->hasOne(LeadDocument::class)
            ->where('checklist_key', 'like', '%face%')
            ->where('status', '!=', LeadDocument::STATUS_REJECTED)
            ->where('mime', 'like', 'image/%')
            ->latest();
    }

    /**
     * Public URL of the applicant's Face image, or null when none uploaded.
     * Prefers an eager-loaded relation; falls back to a query otherwise.
     */
    public function faceImageUrl(): ?string
    {
        $doc = $this->relationLoaded('faceImage') ? $this->faceImage : $this->faceImage()->first();

        // Client uploads live on the private disk (Privacy-Act fix), so serve
        // the avatar through the access-checked staff download route rather
        // than a public URL. Every staff role can reach admin.documents.download.
        return $doc && $doc->file_path
            ? route('admin.documents.download', ['docId' => $doc->id]).'?inline=1'
            : null;
    }

    /**
     * Managed agreements for this lead (Build 11.D Phase 2). Distinct from
     * documents — agreements have their own lifecycle (draft → sent → signed)
     * and the signing audit trail. The pre-existing AgreementGenerator
     * service writes generated PDFs as LeadDocument rows; that channel
     * still works and is unrelated.
     */
    public function agreements()
    {
        return $this->hasMany(\App\Models\Agreement::class);
    }

    /** AI analysis is written by a background job — don't log it as a user edit. */
    public function activityIgnoredAttributes(): array
    {
        return ['ai_analysis', 'ai_analysis_status'];
    }

    public function educationExps(): HasMany
    {
        return $this->hasMany(LeadEducationExp::class);
    }

    public function studyPlans(): HasMany
    {
        return $this->hasMany(LeadStudyPlan::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    /** Staff member who last touched the event_notes field. */
    public function eventNotesEditor()
    {
        return $this->belongsTo(User::class, 'event_notes_updated_by');
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function eventSession()
    {
        return $this->belongsTo(EventSession::class);
    }

    public function educationEventRegistrations()
    {
        return $this->hasMany(\App\Models\EducationEventRegistration::class);
    }
}
