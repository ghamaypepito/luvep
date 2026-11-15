<?php

namespace App\Models;

use App\Traits\HasPaymentLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Accounts Receivable — an invoice ePathways issued to a client. Optionally
 * linked to a Lead; `client_name` is a snapshot so the row reads correctly even
 * if the lead is later removed.
 */
class FinanceReceivable extends Model
{
    use HasPaymentLedger;

    protected $fillable = [
        'invoice_no', 'lead_id', 'invoice_document_id', 'client_name', 'description', 'currency',
        'amount', 'issue_date', 'due_date', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'issue_date' => 'date',
        'due_date' => 'date',
        'internal_settlement_pending' => 'boolean',
    ];

    protected $appends = ['amount_paid', 'balance', 'payment_status', 'days_past_due', 'aging_bucket'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function invoicePayments(): HasMany
    {
        return $this->hasMany(CaseFinancePayment::class, 'invoice_document_id', 'invoice_document_id');
    }

    public function isStudentVisaInvoice(): bool
    {
        return $this->invoice_document_id && $this->lead?->is_immigration_case
            && in_array(strtolower(trim((string) $this->lead->inz_visa_type)), ['student visa', 'student visa (fee paying)'], true);
    }

    public function canFlagInternalSettlement(float $allocated): bool
    {
        return $this->status !== 'void' && (float) $this->amount > 0
            && round($allocated, 2) >= (float) $this->amount;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
