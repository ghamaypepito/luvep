<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;

class LeadDocument extends Model
{
    use LogsActivity;

    public const STATUS_SUBMITTED = 'Submitted';

    public const STATUS_UNDER_REVIEW = 'UnderReview';

    // The manager has reviewed the document and referred it to the Licensed
    // Immigration Adviser for verification. INTERNAL — never shown to the
    // client (mapped to "Under review" on client surfaces via clientStatus()).
    public const STATUS_CHECKED = 'Checked';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_REJECTED = 'Rejected';

    public const STATUS_STAFF_SHARED = 'StaffShared';

    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_GENERATED = 'generated';

    protected $fillable = [
        'lead_id', 'dependent_id', 'request_id', 'checklist_key', 'media_file_id', 'media_target_key',
        'original_name', 'file_path', 'mime', 'size',
        'status', 'source', 'source_variant', 'note', 'notes', 'inz_form_version_id',
        'uploaded_by', 'reviewed_by', 'reviewed_at',
        'engagement_signer_id', 'client_signature_path',
        'client_signed_at', 'client_signer_name', 'client_signer_ip',
        'invoice_number',
        'invoice_total',
        'gdrive_file_id', 'gdrive_synced_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'client_signed_at' => 'datetime',
        'size' => 'integer',
        'notes' => 'array',
    ];

    /**
     * Client-safe status. The manager's internal "Checked" (referred to the
     * adviser) is never exposed to the client — they see "Under review" until
     * the adviser Approves or Rejects. All other statuses pass through.
     */
    public static function clientStatus(?string $status): ?string
    {
        return $status === self::STATUS_CHECKED ? self::STATUS_UNDER_REVIEW : $status;
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function request()
    {
        return $this->belongsTo(LeadDocumentRequest::class, 'request_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function proofReview()
    {
        return $this->hasOne(PaymentProofReview::class, 'document_id');
    }

    public function invoicePayments()
    {
        return $this->hasMany(CaseFinancePayment::class, 'invoice_document_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
