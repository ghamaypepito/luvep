<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Build 11.D Phase 2 — Generated agreement instance.
 *
 * Lifecycle:
 *   draft → sent → viewed → signed   (happy path)
 *         ↘ voided                   (consultant-cancelled)
 *
 * Drafts hold rendered content + PDF path. send() stamps sent_at and
 * generates the tracker_signing_token (the bearer credential clients
 * use to open the signing URL). Phase 3 (signing) populates the signer
 * audit fields (name, ip, ua, signature_data) and signed_pdf_path.
 */
class Agreement extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_VIEWED = 'viewed';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_VOIDED = 'voided';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'lead_id',
        'agreement_template_id',
        'generated_by_user_id',
        'title',
        'content_rendered',
        'pdf_path',
        'status',
        'sent_at',
        'viewed_at',
        'signed_at',
        'signer_name',
        'signer_ip',
        'signer_user_agent',
        'signature_data',
        'signed_pdf_path',
        'variables_used',
        'tracker_signing_token',
        'sign_reminded_at',
        'choose_reminded_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'signed_at' => 'datetime',
        'sign_reminded_at' => 'datetime',
        'choose_reminded_at' => 'datetime',
        'variables_used' => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(AgreementTemplate::class, 'agreement_template_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }
}
