<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One receipt on a case's payment ledger (an instalment or a full payment).
 * The case's paid / owed / settled figures are summed from these rows.
 */
class CaseFinancePayment extends Model
{
    protected $fillable = ['lead_id', 'paid_at', 'amount', 'method', 'reference', 'recorded_by', 'invoice_document_id', 'currency'];

    public function proofReviews()
    {
        return $this->hasMany(PaymentProofReview::class, 'finance_payment_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (self $payment) {
            if ($payment->proofReviews()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['payment' => 'This receipt has verified payment evidence. Ask Finance to review a correction instead of deleting it.']);
            }
        });
        static::updating(function (self $payment) {
            if ($payment->isDirty(['amount', 'currency', 'invoice_document_id', 'reference', 'paid_at']) && $payment->proofReviews()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['payment' => 'A receipt linked to verified proof cannot be changed through this action.']);
            }
        });
    }

    protected $casts = [
        'paid_at' => 'date',
        'amount' => 'decimal:2',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
